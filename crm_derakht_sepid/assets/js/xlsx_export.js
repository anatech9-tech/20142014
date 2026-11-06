/**
 * xlsx_export.js — تولید فایل .xlsx واقعی در مرورگر (بدون هیچ کتابخانهٔ بیرونی)
 *
 * چرا: خروجی‌های قبلی با Tab جدا می‌شدند ولی با پسوند/نوع csv فرستاده می‌شدند،
 * و اکسل csv را با کاما می‌شکند؛ نتیجه این بود که کل ردیف در یک سلول می‌افتاد.
 * اینجا یک بستهٔ OOXML استاندارد (ZIP با روش store) ساخته می‌شود.
 *
 * استفاده:
 *   exportTableToXlsx('reportTable', 'گزارش_مرداد');   // از روی یک <table>
 *   exportRowsToXlsx([['نام','مبلغ'],['علی',1000]], 'file', true);
 */
(function (global) {
    'use strict';

    /* ── CRC32 ─────────────────────────────────────────────── */
    var CRC_TABLE = (function () {
        var t = new Uint32Array(256), c, n, k;
        for (n = 0; n < 256; n++) {
            c = n;
            for (k = 0; k < 8; k++) c = (c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1);
            t[n] = c >>> 0;
        }
        return t;
    })();

    function crc32(bytes) {
        var c = 0xFFFFFFFF;
        for (var i = 0; i < bytes.length; i++) {
            c = CRC_TABLE[(c ^ bytes[i]) & 0xFF] ^ (c >>> 8);
        }
        return (c ^ 0xFFFFFFFF) >>> 0;
    }

    function utf8(str) {
        return new TextEncoder().encode(str);
    }

    /* ── ZIP (روش store، بدون فشرده‌سازی) ──────────────────── */
    function zip(files) {
        var chunks = [], central = [], offset = 0;

        files.forEach(function (f) {
            var name = utf8(f.name), data = utf8(f.data), crc = crc32(data);

            var lh = new DataView(new ArrayBuffer(30));
            lh.setUint32(0, 0x04034b50, true);
            lh.setUint16(4, 20, true);
            lh.setUint16(6, 0x0800, true);   // UTF-8 flag
            lh.setUint16(8, 0, true);        // store
            lh.setUint32(14, crc, true);
            lh.setUint32(18, data.length, true);
            lh.setUint32(22, data.length, true);
            lh.setUint16(26, name.length, true);
            chunks.push(new Uint8Array(lh.buffer), name, data);

            var ch = new DataView(new ArrayBuffer(46));
            ch.setUint32(0, 0x02014b50, true);
            ch.setUint16(4, 20, true);
            ch.setUint16(6, 20, true);
            ch.setUint16(8, 0x0800, true);
            ch.setUint16(10, 0, true);
            ch.setUint32(16, crc, true);
            ch.setUint32(20, data.length, true);
            ch.setUint32(24, data.length, true);
            ch.setUint16(28, name.length, true);
            ch.setUint32(42, offset, true);
            central.push({ head: new Uint8Array(ch.buffer), name: name });

            offset += 30 + name.length + data.length;
        });

        var cStart = offset, cSize = 0;
        central.forEach(function (c) {
            chunks.push(c.head, c.name);
            cSize += c.head.length + c.name.length;
        });

        var end = new DataView(new ArrayBuffer(22));
        end.setUint32(0, 0x06054b50, true);
        end.setUint16(8, central.length, true);
        end.setUint16(10, central.length, true);
        end.setUint32(12, cSize, true);
        end.setUint32(16, cStart, true);
        chunks.push(new Uint8Array(end.buffer));

        return new Blob(chunks, {
            type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        });
    }

    /* ── کمکی‌ها ───────────────────────────────────────────── */
    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
            // حذف کاراکترهای کنترلی که XML نمی‌پذیرد
            .replace(/[\x00-\x08\x0B\x0C\x0E-\x1F]/g, '');
    }

    function colLetter(n) {
        var s = '';
        while (n > 0) { n--; s = String.fromCharCode(65 + (n % 26)) + s; n = Math.floor(n / 26); }
        return s;
    }

    // «۱٬۲۳۴ ت» یا «1,234» → عدد؛ در غیر این صورت null
    function asNumber(v) {
        if (v === null || v === undefined) return null;
        var s = String(v).trim();
        if (!s) return null;
        var fa = '۰۱۲۳۴۵۶۷۸۹', ar = '٠١٢٣٤٥٦٧٨٩';
        s = s.replace(/[۰-۹]/g, function (d) { return fa.indexOf(d); })
             .replace(/[٠-٩]/g, function (d) { return ar.indexOf(d); });
        // پسوند واحد پول را بردار: «۱٬۲۳۴٬۵۶۷ ت» → عدد
        s = s.replace(/(تومان|ریال|ت)\s*$/u, '').trim();
        s = s.replace(/[,٬\s]/g, '');
        if (!/^-?\d+(\.\d+)?$/.test(s)) return null;
        var n = parseFloat(s);
        return isFinite(n) ? n : null;
    }

    /* ── ساخت فایل ─────────────────────────────────────────── */
    function build(rows, rtl) {
        var shared = [], sIdx = {};
        function sid(v) {
            if (!(v in sIdx)) { sIdx[v] = shared.length; shared.push(v); }
            return sIdx[v];
        }

        var widths = [], sheet = '';
        rows.forEach(function (row, r) {
            sheet += '<row r="' + (r + 1) + '">';
            row.forEach(function (cell, c) {
                var ref = colLetter(c + 1) + (r + 1);
                var txt = (cell === null || cell === undefined) ? '' : String(cell);
                var num = (r === 0) ? null : asNumber(txt);
                var style = (r === 0) ? 2 : (txt.indexOf('\n') !== -1 ? 4 : 0);

                var longest = 0;
                txt.split('\n').forEach(function (l) { longest = Math.max(longest, l.length); });
                widths[c] = Math.min(50, Math.max(widths[c] || 10, longest + 3));

                sheet += '<c r="' + ref + '"' + (style ? ' s="' + style + '"' : '');
                if (num !== null) sheet += '><v>' + num + '</v></c>';
                else sheet += ' t="s"><v>' + sid(txt) + '</v></c>';
            });
            sheet += '</row>';
        });

        var cols = '<cols>';
        widths.forEach(function (w, i) {
            cols += '<col min="' + (i + 1) + '" max="' + (i + 1) + '" width="' + w + '" customWidth="1"/>';
        });
        cols += '</cols>';

        var X = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        var NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

        var sheetXml = X + '<worksheet xmlns="' + NS + '"><sheetViews><sheetView workbookViewId="0"'
            + (rtl ? ' rightToLeft="1"' : '') + '/></sheetViews>' + cols
            + '<sheetData>' + sheet + '</sheetData></worksheet>';

        var ss = X + '<sst xmlns="' + NS + '" count="' + shared.length
            + '" uniqueCount="' + shared.length + '">';
        shared.forEach(function (s) {
            ss += '<si><t xml:space="preserve">' + esc(s) + '</t></si>';
        });
        ss += '</sst>';

        var styles = X + '<styleSheet xmlns="' + NS + '">'
            + '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
            + '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
            + '<fills count="3"><fill><patternFill patternType="none"/></fill>'
            + '<fill><patternFill patternType="gray125"/></fill>'
            + '<fill><patternFill patternType="solid"><fgColor rgb="FF1E3A5F"/></patternFill></fill></fills>'
            + '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            + '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            + '<cellXfs count="5">'
            + '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            + '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            + '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            + '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            + '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf>'
            + '</cellXfs></styleSheet>';

        return zip([
            { name: '[Content_Types].xml', data: X
                + '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                + '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                + '<Default Extension="xml" ContentType="application/xml"/>'
                + '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                + '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                + '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
                + '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                + '</Types>' },
            { name: '_rels/.rels', data: X
                + '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                + '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                + '</Relationships>' },
            { name: 'xl/workbook.xml', data: X
                + '<workbook xmlns="' + NS + '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                + '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>' },
            { name: 'xl/_rels/workbook.xml.rels', data: X
                + '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                + '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                + '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                + '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
                + '</Relationships>' },
            { name: 'xl/styles.xml', data: styles },
            { name: 'xl/sharedStrings.xml', data: ss },
            { name: 'xl/worksheets/sheet1.xml', data: sheetXml }
        ]);
    }

    function save(blob, filename) {
        if (!/\.xlsx$/i.test(filename)) filename += '.xlsx';
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url; a.download = filename;
        document.body.appendChild(a); a.click();
        setTimeout(function () { document.body.removeChild(a); URL.revokeObjectURL(url); }, 1000);
    }

    /* ── API عمومی ─────────────────────────────────────────── */
    function exportRowsToXlsx(rows, filename, rtl) {
        if (!rows || !rows.length) { alert('داده‌ای برای خروجی وجود ندارد'); return; }
        save(build(rows, rtl !== false), filename || 'export');
    }

    function exportTableToXlsx(table, filename, rtl) {
        if (typeof table === 'string') table = document.getElementById(table);
        // اگر یک ظرف (div) پاس داده شده، جدول داخلش را بردار
        if (table && table.tagName !== 'TABLE') table = table.querySelector('table');
        if (!table) { alert('جدولی برای خروجی وجود ندارد'); return; }

        var rows = [];
        table.querySelectorAll('tr').forEach(function (tr) {
            if (tr.offsetParent === null && tr.closest('thead') === null) return; // ردیف مخفی
            var row = [], skip = false;
            tr.querySelectorAll('th, td').forEach(function (cell) {
                if (cell.classList.contains('no-export')) return;
                // ستون‌هایی که فقط دکمه/آیکون دارند به اکسل نمی‌روند
                if (cell.querySelector('button, .btn, input[type=checkbox]') &&
                    !cell.textContent.trim()) { return; }
                var txt = cell.innerText.replace(/\u200c/g, '').replace(/[ \t]+/g, ' ').trim();
                var span = parseInt(cell.getAttribute('colspan') || '1', 10);
                row.push(txt);
                for (var i = 1; i < span; i++) row.push('');
            });
            if (row.some(function (v) { return v !== ''; })) rows.push(row);
        });

        exportRowsToXlsx(rows, filename, rtl);
    }

    global.exportTableToXlsx = exportTableToXlsx;
    global.exportRowsToXlsx = exportRowsToXlsx;
    // سازگاری با کدهای قدیمی
    global.exportCSV = function (data, filename) {
        if (!data || !data.length) return;
        var headers = Object.keys(data[0]);
        var rows = [headers].concat(data.map(function (r) {
            return headers.map(function (h) { return r[h] == null ? '' : r[h]; });
        }));
        exportRowsToXlsx(rows, filename, true);
    };
})(window);

/* ────────────────────────────────────────────────────────────
 * افزودن خودکار دکمهٔ «خروجی اکسل» بالای هر جدول داده
 *
 * هر <table> که داخل .table-container باشد و حداقل یک ردیف داده و
 * ۳ ستون داشته باشد، خودکار یک دکمهٔ خروجی می‌گیرد.
 * برای صرف‌نظر کردن از یک جدول: <table data-no-export>
 * ──────────────────────────────────────────────────────────── */
(function () {
    'use strict';

    function pageName() {
        var m = location.search.match(/[?&]page=([a-z_]+)/i);
        var base = m ? m[1] : 'export';
        var h1 = document.querySelector('.page-title, h1, .card-title');
        var t = h1 ? h1.textContent.trim().replace(/[\\/:*?"<>|]/g, '').slice(0, 40) : '';
        return (t || base) + '_' + new Date().toLocaleDateString('fa-IR-u-nu-latn').replace(/\//g, '-');
    }

    function attach() {
        if (!window.exportTableToXlsx) return;
        var seen = 0;

        document.querySelectorAll('.table-container > table, .card table').forEach(function (tbl) {
            if (tbl.hasAttribute('data-no-export')) return;
            if (tbl.closest('.modal-overlay, .modal')) return;      // جدول‌های داخل مودال
            if (tbl.dataset.xlsxReady) return;
            // اگر صفحه خودش دکمهٔ خروجی دارد، دکمهٔ دوم اضافه نکن
            if (document.querySelector('[onclick*="exportReportCSV"], [onclick*="exportTableToXlsx"], [href*="download_excel"]')) return;

            var bodyRows = tbl.querySelectorAll('tbody tr');
            var headCols = tbl.querySelectorAll('thead th');
            if (bodyRows.length < 1 || headCols.length < 3) return;

            // جدول‌های «رکوردی یافت نشد»
            if (bodyRows.length === 1 && bodyRows[0].querySelectorAll('td').length <= 1) return;

            tbl.dataset.xlsxReady = '1';
            if (!tbl.id) tbl.id = 'xlsxTable' + (++seen);

            var bar = document.createElement('div');
            bar.className = 'xlsx-bar';
            bar.style.cssText = 'display:flex;justify-content:flex-end;margin:0 0 8px';

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-success btn-sm';
            btn.innerHTML = '\u2B07 \u062E\u0631\u0648\u062C\u06CC \u0627\u06A9\u0633\u0644';
            btn.onclick = function () { window.exportTableToXlsx(tbl, pageName()); };
            bar.appendChild(btn);

            var host = tbl.closest('.table-container') || tbl;
            host.parentNode.insertBefore(bar, host);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attach);
    } else {
        attach();
    }
    window.attachXlsxButtons = attach;
})();

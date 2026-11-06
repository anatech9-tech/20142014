// Work Orders UI - Daily Reports

var WO_UI_TEXT = {
    fa: {teamRequired: 'حداقل یک واحد انتخاب کنید', itemRequired: 'حداقل یک ردیف فعالیت وارد کنید', invalidDate: 'تاریخ نامعتبر', sent: 'دستور کار با موفقیت ارسال شد', unknown: 'ناشناخته', error: 'خطا', sendError: 'خطا در ارسال: '},
    ar: {teamRequired: 'اختر وحدة واحدة على الأقل', itemRequired: 'أدخل بند نشاط واحداً على الأقل', invalidDate: 'التاريخ غير صالح', sent: 'تم إرسال أمر العمل بنجاح', unknown: 'غير معروف', error: 'خطأ', sendError: 'خطأ في الإرسال: '},
    en: {teamRequired: 'Select at least one unit', itemRequired: 'Enter at least one activity row', invalidDate: 'Invalid date', sent: 'Work order sent successfully', unknown: 'Unknown', error: 'Error', sendError: 'Send error: '}
};
function woUiText(key) {
    var lang = (document.documentElement.lang || 'fa').toLowerCase().split('-')[0];
    return (WO_UI_TEXT[lang] || WO_UI_TEXT.fa)[key];
}

function printReports() {
    var content = document.getElementById('reportsArea').innerHTML;
    var lang = (document.documentElement.lang || 'fa').toLowerCase().split('-')[0];
    var labels = {
        fa: 'گزارش کار روزانه',
        ar: 'تقرير العمل اليومي',
        en: 'Daily Work Report'
    };
    var title = labels[lang] || labels.fa;
    var dir = document.documentElement.dir || (lang === 'en' ? 'ltr' : 'rtl');
    var align = dir === 'ltr' ? 'left' : 'right';
    var w = window.open('', '', 'width=900,height=700');
    w.document.write('<html lang="' + lang + '" dir="' + dir + '"><head><title>' + title + '</title><style>body{font-family:Vazirmatn,sans-serif;direction:' + dir + ';padding:20px;line-height:1.8}table{width:100%}td,th{padding:8px;border:1px solid #ddd;text-align:' + align + '}.card{border:1px solid #ddd;border-radius:8px;padding:12px;margin-bottom:12px}</style></head><body><h2>' + title + '</h2>' + content + '</body></html>');
    w.document.close();
    w.print();
}

function addWoRow() {
    var tbody = document.getElementById('woItemsBody');
    if (!tbody) return;
    var n = tbody.rows.length + 1;
    var tr = document.createElement('tr');
    tr.innerHTML = '<td style="text-align:center">' + n + '</td>' +
        '<td><input class="inp" name="item_description[]"></td>' +
        '<td><input class="inp" name="item_progress[]" placeholder="%"></td>' +
        '<td><input class="inp" name="item_next_day[]"></td>' +
        '<td style="text-align:center"><button type="button" onclick="removeWoRow(this)" style="background:none;border:none;color:#ef4444;cursor:pointer;font-size:16px">✕</button></td>';
    tbody.appendChild(tr);
}

function removeWoRow(btn) {
    var tbody = document.getElementById('woItemsBody');
    if (!tbody || tbody.rows.length <= 1) return;
    var row = btn.parentNode.parentNode;
    tbody.removeChild(row);
}

function toggleTeamCheck(cb) {
    var lbl = cb.closest('label');
    if (cb.checked) { lbl.style.borderColor = '#667eea'; lbl.style.background = '#eef2ff'; }
    else { lbl.style.borderColor = '#e2e8f0'; lbl.style.background = 'transparent'; }
}

function submitWorkOrder(e) {
    e.preventDefault();
    var teams = [];
    document.querySelectorAll('input[name="target_teams[]"]:checked').forEach(function(cb) { teams.push(cb.value); });
    if (teams.length === 0) { alert(woUiText('teamRequired')); return false; }
    var descs = document.querySelectorAll('#woItemsBody input[name="item_description[]"]');
    var hasItem = false;
    descs.forEach(function(d) { if (d.value.trim()) hasItem = true; });
    if (!hasItem) { alert(woUiText('itemRequired')); return false; }
    var jalaliDate = document.getElementById('wo_date').value;
    if (jalaliDate.split('/').length !== 3) { alert(woUiText('invalidDate')); return false; }
    // تاریخ شمسی با اسلش ارسال میشه؛ تبدیل به میلادی سمت سرور (api/work_orders.php) انجام میشه
    var gDate = jalaliDate;
    var promises = [];
    var csrfInput = document.querySelector('input[name="csrf_token"]');
    var csrfToken = csrfInput ? csrfInput.value : '';

    var form = document.createElement('form');
    form.method = 'POST';
    form.action = 'index.php?page=work_orders';
    
    function addHidden(name, val) {
        var inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = name;
        inp.value = val;
        form.appendChild(inp);
    }
    
    addHidden('action', 'create');
    if (csrfToken) addHidden('csrf_token', csrfToken);
    addHidden('order_date', gDate);
    teams.forEach(function(team) {
        addHidden('target_teams[]', team);
    });
    document.querySelectorAll('#woItemsBody input[name="item_description[]"]').forEach(function(d, i) {
        if (d.value.trim()) {
            addHidden('item_description[]', d.value.trim());
            var prog = document.querySelectorAll('#woItemsBody input[name="item_progress[]"]')[i];
            var next = document.querySelectorAll('#woItemsBody input[name="item_next_day[]"]')[i];
            addHidden('item_progress[]', prog ? prog.value.trim() : '');
            addHidden('item_next_day[]', next ? next.value.trim() : '');
        }
    });
    document.body.appendChild(form);
    form.submit();
    return false;
}

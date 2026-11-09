        </section>
        <script src="assets/js/app.js"></script>
        <script src="assets/js/xlsx_export.js?v=13"></script>
        <script src="assets/js/work_orders_ui.js"></script>
        <script src="assets/js/jalali_datepicker.js"></script>
        <script src="assets/js/time24_picker.js"></script>
        <script src="assets/js/pwa.js"></script>
        <script>
        /* §v18: بعد از ثبت/ویرایش، همان فیلتر و همان جای صفحه بماند */
        (function(){
            var K='arena_ctx_v18';
            function pg(){try{return new URLSearchParams(location.search).get('page')||'';}catch(e){return '';}}
            function stamp(f){
                try{
                    if(!f||String(f.method||'get').toLowerCase()!=='post')return;
                    if(!f.querySelector('input[name="_ctx"]')){
                        var i=document.createElement('input');i.type='hidden';i.name='_ctx';f.appendChild(i);
                    }
                    f.querySelector('input[name="_ctx"]').value=location.search.replace(/^\?/,'');
                    sessionStorage.setItem(K,JSON.stringify({p:pg(),y:window.pageYOffset||document.documentElement.scrollTop||0,t:Date.now()}));
                }catch(e){}
            }
            document.addEventListener('submit',function(e){stamp(e.target);},true);
            var os=HTMLFormElement.prototype.submit;
            HTMLFormElement.prototype.submit=function(){stamp(this);return os.apply(this,arguments);};
            window.addEventListener('load',function(){
                try{
                    var d=JSON.parse(sessionStorage.getItem(K)||'null');sessionStorage.removeItem(K);
                    if(!d||!d.y||d.p!==pg()||Date.now()-d.t>20000||location.hash)return;
                    setTimeout(function(){window.scrollTo(0,d.y);},60);
                }catch(e){}
            });
        })();
        </script>
        <script>lucide.createIcons();</script>
    </main>
</body>
</html>

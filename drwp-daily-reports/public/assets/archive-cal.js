(function(){
  if (typeof window.drwpArchCal === 'undefined') return;
  var dates = window.drwpArchCal.dates || {};
  var fromEl = document.getElementById('drwp-arch-from');
  var toEl = document.getElementById('drwp-arch-to');
  var grid = document.getElementById('drwp-arch-cal-grid');
  var titleEl = document.getElementById('drwp-arch-cal-title');
  if (!grid || !fromEl || !toEl) return;

  function pad(n){return n<10?('0'+n):(''+n);}
  function fmt(d){return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate());}
  function parseDate(s){
    if(!s)return null;
    var m=s.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if(!m)return null;
    return new Date(parseInt(m[1],10),parseInt(m[2],10)-1,parseInt(m[3],10));
  }
  function startOfMonth(y,m){return new Date(y,m,1);}

  var init = parseDate(fromEl.value) || parseDate(toEl.value) || new Date();
  var cursor = startOfMonth(init.getFullYear(), init.getMonth());
  var pendingStart = null;

  function render(){
    var y = cursor.getFullYear(), m = cursor.getMonth();
    titleEl.textContent = y + '年 ' + (m+1) + '月';
    grid.innerHTML = '';
    var dows = ['日','月','火','水','木','金','土'];
    dows.forEach(function(d,i){
      var el = document.createElement('div');
      el.className = 'dow' + (i===0?' sun':(i===6?' sat':''));
      el.textContent = d;
      grid.appendChild(el);
    });
    var firstDow = new Date(y,m,1).getDay();
    var daysInMonth = new Date(y,m+1,0).getDate();
    var today = fmt(new Date());
    var fromVal = fromEl.value, toVal = toEl.value;
    for (var i=0;i<firstDow;i++){
      var emp = document.createElement('div'); emp.className='drwp-archive-cal-day empty'; grid.appendChild(emp);
    }
    for (var d=1; d<=daysInMonth; d++){
      var date = new Date(y,m,d);
      var key = fmt(date);
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'drwp-archive-cal-day';
      btn.textContent = d;
      btn.dataset.date = key;
      if (dates[key]) btn.classList.add('has-reports');
      if (key === today) btn.classList.add('today');
      if (fromVal && toVal) {
        if (key >= fromVal && key <= toVal) btn.classList.add('in-range');
        if (key === fromVal || key === toVal) btn.classList.add('range-edge');
      } else if (pendingStart && pendingStart === key) {
        btn.classList.add('range-edge');
      }
      grid.appendChild(btn);
    }
  }

  grid.addEventListener('click', function(e){
    var btn = e.target.closest('.drwp-archive-cal-day');
    if (!btn || btn.classList.contains('empty')) return;
    var key = btn.dataset.date;
    if (!pendingStart && !(fromEl.value && toEl.value && fromEl.value !== toEl.value)) {
      pendingStart = key;
      fromEl.value = key;
      toEl.value = key;
    } else {
      var anchor = pendingStart || fromEl.value;
      if (key < anchor) { fromEl.value = key; toEl.value = anchor; }
      else { fromEl.value = anchor; toEl.value = key; }
      pendingStart = null;
    }
    render();
  });

  document.getElementById('drwp-arch-cal-prev').addEventListener('click', function(){
    cursor = startOfMonth(cursor.getFullYear(), cursor.getMonth()-1); render();
  });
  document.getElementById('drwp-arch-cal-next').addEventListener('click', function(){
    cursor = startOfMonth(cursor.getFullYear(), cursor.getMonth()+1); render();
  });
  [fromEl, toEl].forEach(function(el){
    el.addEventListener('change', function(){ pendingStart = null; render(); });
  });

  render();
})();

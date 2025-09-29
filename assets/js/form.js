(function(){
  function qs(s,ctx){ return (ctx||document).querySelector(s); }
  function qsa(s,ctx){ return Array.prototype.slice.call((ctx||document).querySelectorAll(s)); }
  function setMsg(id, msg){ var el = qs('.field-msg[data-for="'+id+'"]', form); if (el){ el.textContent = msg || ''; } }
  function clearAllMsgs(){ qsa('.field-msg', form).forEach(function(el){ el.textContent=''; }); }
  function debounce(fn, ms){ var t; return function(){ var a=arguments, c=this; clearTimeout(t); t=setTimeout(function(){ fn.apply(c,a); }, ms||350); }; }
  function isMobile(){ try{ if (window.matchMedia && window.matchMedia('(max-width: 767px)').matches) return true; }catch(e){} return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent||''); }
  function safeScroll(target){ if (isMobile()) return; try{ window.scrollTo({ top: (target||document.body).offsetTop - 10, behavior:'smooth' }); }catch(e){} }
  function setLoading(btn, on){ if(!btn) return; if(on){ btn.classList.add('loading'); if(!btn.querySelector('.spinner')){ var sp=document.createElement('span'); sp.className='spinner'; btn.appendChild(sp);} } else { btn.classList.remove('loading'); } }

  var wrap = qs('.txp-form-wrap'); if (!wrap) return;
  var form = qs('.txp-form', wrap);
  var steps = qsa('.step', form);
  function showStep(n){
    steps.forEach(function(st,i){
      if(i === (n-1)){ st.hidden=false; void st.offsetWidth; st.classList.add('active'); }
      else { st.classList.remove('active'); st.hidden=true; }
    });
    safeScroll(wrap);
  }

  // Masks
  var cpf = qs('#_cpf', form);
  if (cpf){
    cpf.addEventListener('input', function(){
      var v = cpf.value.replace(/\D+/g,'');
      if (v.length>11) v=v.slice(0,11);
      var out = v;
      if (v.length>9) out = v.replace(/(\d{3})(\d{3})(\d{3})(\d{0,2})/, "$1.$2.$3-$4");
      else if (v.length>6) out = v.replace(/(\d{3})(\d{3})(\d{0,3})/, "$1.$2.$3");
      else if (v.length>3) out = v.replace(/(\d{3})(\d{0,3})/, "$1.$2");
      cpf.value = out;
    });
  }
  var phone = qs('#ncelulartm', form);
  if (phone){
    phone.addEventListener('input', function(){
      var v = phone.value.replace(/\D+/g,'');
      if (v.length>11) v=v.slice(0,11);
      if (v.length>=11){ phone.value = v.replace(/(\d{2})(\d{5})(\d{4})/, "($1) $2-$3"); }
      else if (v.length>=7){ phone.value = v.replace(/(\d{2})(\d{4})(\d{0,4})/, "($1) $2-$3"); }
      else if (v.length>=3){ phone.value = v.replace(/(\d{2})(\d{0,5})/, "($1) $2"); }
      else { phone.value = v; }
    });
  }

  function ajax(op, payload){
    var fd = new FormData();
    fd.append('action','txpfo_check');
    fd.append('op', op);
    fd.append('txpfo_nonce', (qs('input[name="txpfo_nonce"]', form)||{}).value||'');
    Object.keys(payload||{}).forEach(function(k){ fd.append(k, payload[k]); });
    var url = (window.txpfoAjax||{}).ajaxurl || '/wp-admin/admin-ajax.php';
    return fetch(url, { method:'POST', body:fd, credentials:'same-origin' }).then(function(r){ return r.json(); });
  }

  // Realtime CPF
  var lastCpfOk = false;
  function validateCpfRealtime(){
    var v = (qs('#_cpf', form)||{}).value || '';
    return ajax('cpf', {cpf: v}).then(function(resp){
      if(!resp || !resp.success){ setMsg('_cpf','Falha ao validar CPF.'); lastCpfOk=false; return false; }
      if(!resp.data.valid){ setMsg('_cpf','CPF inválido.'); lastCpfOk=false; return false; }
      if(resp.data.duplicate){ setMsg('_cpf','CPF já possui inscrição.'); lastCpfOk=false; return false; }
      setMsg('_cpf',''); lastCpfOk = true; return true;
    }).catch(function(){ setMsg('_cpf','Erro de conexão.'); lastCpfOk=false; return false; });
  }
  if (cpf){ cpf.addEventListener('input', debounce(validateCpfRealtime, 350)); }

  var next = qs('.step[data-step="1"] .next', form);
  var back = qs('.step[data-step="2"] .back', form);
  if (next){
    next.addEventListener('click', function(){
      setLoading(next, true);
      clearAllMsgs();
      var ok = true;
      qsa('.step[data-step="1"] input, .step[data-step="1"] select', form).forEach(function(inp){
        if (inp.hasAttribute('required') && !inp.value.trim()){ ok=false; setMsg(inp.id||inp.name,'Campo obrigatório'); }
      });
      if (!ok){ setLoading(next, false); return; }
      validateCpfRealtime().then(function(cpfOk){
        if (!cpfOk){ setLoading(next, false); return; }
        refreshStock().then(function(){ showStep(2); setLoading(next, false); }).catch(function(){ setLoading(next, false); });
      });
    });
  }
  if (back){ back.addEventListener('click', function(){ showStep(1); }); }

  function refreshStock(){
    var eventId = wrap.getAttribute('data-event');
    return ajax('stock', {event_id: eventId}).then(function(resp){
      if(!resp || !resp.success) return;
      var avail = resp.data.available||{}, stock = resp.data.stock||{}; var hideEmpty = (qs('#sizes-wrap', form).getAttribute('data-hideempty') === '1');
      qsa('#sizes-wrap .size', form).forEach(function(lbl){
        var radio = qs('input[type="radio"]', lbl); var sz = radio?radio.value:''; if(!sz)return;
        var map = {'P':'estoque_p','M':'estoque_m','G':'estoque_g','GG':'estoque_gg','XG':'estoque_xg'};
        var qty = stock[map[sz]];
        var qtyEl = qs('.qty[data-size="'+sz+'"]', lbl);
        var soldEl = qs('.sold', lbl);
        var ok = (typeof qty==='number' && qty>0 && (stock['estoque_geral']>0));
        radio.disabled = !ok; lbl.classList.toggle('disabled', !ok);
        if (ok){
          if (soldEl){ soldEl.remove(); }
          if (qtyEl){ qtyEl.textContent = qty; qtyEl.style.display=''; }
          lbl.style.display='';
        } else {
          if (hideEmpty){ lbl.style.display='none'; }
          else {
            if (qtyEl){ qtyEl.style.display='none'; }
            if (!soldEl){ var s=document.createElement('strong'); s.className='sold'; s.textContent='Indisponível'; var span=qs('span', lbl); (span? span: lbl).insertAdjacentElement('afterend', s); }
            lbl.style.display='';
          }
        }
      });
      var selected = qs('input[name="tcamisetatm"]:checked', form);
      if (selected && selected.disabled){ selected.checked=false; setMsg('tcamisetatm','Tamanho ficou indisponível.'); } else { setMsg('tcamisetatm',''); }
    });
  }
  var sizesWrap = qs('#sizes-wrap', form);
  if (sizesWrap){ sizesWrap.addEventListener('change', function(e){ if(e.target && e.target.name==='tcamisetatm'){ refreshStock(); } }); }

  form.addEventListener('submit', function(e){
    e.preventDefault();
    var submitBtn = qs('.step[data-step="2"] .btn.primary', form); setLoading(submitBtn, true);
    clearAllMsgs();
    var size = qs('input[name="tcamisetatm"]:checked', form);
    if (!size){ setMsg('tcamisetatm','Selecione um tamanho.'); setLoading(submitBtn, false); return; }
    var payload = {
      event_id: wrap.getAttribute('data-event'),
      organizer_id: qs('input[name="organizer_id"]', form).value,
      // campos do formulário
      primeironome: qs('#primeironome', form).value,
      segundonome: qs('#segundonome', form).value,
      _cpf: qs('#_cpf', form).value,
      aemail: qs('#aemail', form).value,
      tcamisetatm: size.value,
      ncelulartm: qs('#ncelulartm', form).value,
      cidadetm: qs('#cidadetm', form).value,
      estado_: qs('#estado_', form).value,
      nomedogrupotm: qs('#nomedogrupotm', form).value
    };
    ajax('finalize', payload).then(function(r2){
      if (!r2 || !r2.success){ var m=(r2&&r2.data&&r2.data.message)?r2.data.message:'Falha ao finalizar.'; setMsg('tcamisetatm', m); setLoading(submitBtn, false); return; }
      var d = r2.data;
      var waLink = 'https://wa.me/55'+ (d.wa||'') +'?text=Ol%C3%A1%2C%20enviei%20o%20comprovante%20da%20inscri%C3%A7%C3%A3o.';
      var pixHTML = '<div class="pix-step"><div class="card">'
        + '<div class="amount-head">'
        +   '<div class="amount">Valor a pagar: <strong>R$ '+ (d.amount||'') +'</strong></div>'
        +   '<div class="right">Recebedor: <strong>'+ (d.pix_name||'') +'</strong></div>'
        + '</div>'
        + '<div class="pix-box">'
        + '  <textarea id="pix-code" readonly>'+ (d.pix_code||'') +'</textarea>'
        + '  <button class="btn out" id="copy-pix">Copiar código PIX</button>'
        + '</div>'
        + '<div class="nav" style="margin-top:12px; justify-content:center">'
        + '  <a class="btn whatsapp" target="_blank" href="'+waLink+'">'
        + '    <svg viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path fill="currentColor" d="M27.1 4.9C24.3 2.1 20.7.6 16.9.6 9.1.6 2.9 6.8 2.9 14.6c0 2.5.6 4.8 1.8 7L2 30l8.6-2.3c2 1.1 4.3 1.7 6.6 1.7 7.8 0 14.1-6.2 14.1-14 0-3.8-1.5-7.3-4.2-9.9zM16.9 27.3c-2.1 0-4.1-.6-5.9-1.6l-.4-.2-5.1 1.4 1.4-5-.2-.4c-1.2-1.9-1.8-4-1.8-6.3 0-6.6 5.4-12 12.1-12 3.2 0 6.2 1.2 8.4 3.5 2.2 2.3 3.5 5.2 3.5 8.4 0 6.6-5.4 12.2-12 12.2zm6.8-9.1c-.4-.2-2.3-1.1-2.7-1.2-.4-.2-.7-.2-1 .2-.3.4-1.1 1.2-1.3 1.5-.2.3-.5.3-.9.1-.4-.2-1.6-.6-3-1.9-1.1-1-1.9-2.3-2.1-2.7-.2-.4 0-.6.2-.8.2-.2.4-.5.6-.7.2-.2.3-.4.4-.6.2-.2.1-.5 0-.7-.1-.2-1-2.4-1.4-3.3-.4-.9-.7-.8-1-.8h-.9c-.3 0-.7.1-1.1.5-.4.4-1.4 1.4-1.4 3.4s1.4 3.9 1.6 4.2c.2.3 2.8 4.3 6.7 6 .9.4 1.6.6 2.1.8.9.3 1.7.3 2.3.2.7-.1 2.3-.9 2.6-1.7.3-.8.3-1.5.2-1.7-.1-.3-.4-.4-.8-.6z"/></svg>'
        + '    Enviar comprovante'
        + '  </a>'
        + '</div>'
        + '<div class="fake-timer">Finalize em <span id="fake-timer">05:00</span></div>'
        + '<ol class="instructions" style="margin-top:12px">'
        + '<li>Abra o aplicativo do seu banco;</li>'
        + '<li>Entre na área Pix e busque a opção de pagar com Pix Copia e Cola;</li>'
        + '<li>Insira o código copiado desta cobrança;</li>'
        + '<li>Revise o pagamento e confirme;</li>'
        + '<li>Envie o comprovante no Whatsapp para confirmar sua inscrição.</li>'
        + '</ol>'
        + '</div></div>';
      wrap.innerHTML = pixHTML;
      var cp = qs('#copy-pix'); if (cp){ cp.addEventListener('click', function(){ var ta = qs('#pix-code'); ta.select(); ta.setSelectionRange(0,99999); try{ document.execCommand('copy'); var prev=cp.textContent; cp.textContent='✅ Copiado!'; setTimeout(function(){ cp.textContent=prev; },1200);}catch(e){} }); }
      var ft = qs('#fake-timer'); if (ft){ var secs=5*60; var iv=setInterval(function(){ secs--; var m=String(Math.floor(secs/60)).padStart(2,'0'); var s=String(secs%60).padStart(2,'0'); ft.textContent=m+':'+s; if(secs<=0){ clearInterval(iv); ft.textContent='00:00'; } },1000); }
      safeScroll(wrap);
    }).catch(function(){ setLoading(submitBtn, false); });
  });
})();

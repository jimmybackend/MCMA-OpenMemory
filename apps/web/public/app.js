(() => {
  const $ = id => document.getElementById(id);
  const status=$('status'),statusText=$('statusText'),login=$('login'),logout=$('logout'),adminLink=$('adminLink');
  const identity=$('identity'),avatar=$('avatar'),avatarFallback=$('avatarFallback'),identityName=$('identityName'),identityEmail=$('identityEmail');
  const account=$('account'),registerBox=$('registerBox'),register=$('register');
  const form=$('askForm'),send=$('send'),answer=$('answer');
  const answerMeta=$('answerMeta'),answerSource=$('answerSource'),answerTokens=$('answerTokens'),answerCredits=$('answerCredits'),answerRemembered=$('answerRemembered');
  const apiKeysBox=$('apiKeysBox'),createKey=$('createKey'),keyList=$('keyList'),newKey=$('newKey');
  const stripeBox=$('stripeBox'),stripePackages=$('stripePackages'),stripeStatus=$('stripeStatus');

  async function api(path,options={}){
    const response=await fetch(path,{credentials:'same-origin',...options,headers:{'Content-Type':'application/json',...(options.headers||{})}});
    const data=await response.json().catch(()=>({}));
    if(!response.ok){const e=new Error(data.message||'Error HTTP '+response.status);e.status=response.status;e.code=data.error;throw e;}
    return data;
  }

  const number=v=>new Intl.NumberFormat('es-MX').format(Number(v||0));

  function setSessionState(state,text){
    status.classList.remove('active','pending','inactive');
    status.classList.add(state);
    statusText.textContent=text;
  }

  function clearIdentity(){
    identity.hidden=true;
    avatar.hidden=true;
    avatar.removeAttribute('src');
    avatarFallback.hidden=false;
    identityName.textContent='Usuario';
    identityEmail.textContent='';
  }

  function showIdentity(profile={}){
    const email=typeof profile.email==='string'?profile.email:'';
    const name=typeof profile.name==='string'&&profile.name.trim()!==''?profile.name.trim():(email||'Cuenta Google');
    const picture=typeof profile.picture==='string'?profile.picture:'';
    identityName.textContent=name;
    identityEmail.textContent=email;
    const source=(name||email||'M').trim();
    avatarFallback.textContent=(source[0]||'M').toUpperCase();
    avatarFallback.hidden=false;
    avatar.hidden=true;
    if(picture){
      avatar.onload=()=>{avatar.hidden=false;avatarFallback.hidden=true;};
      avatar.onerror=()=>{avatar.hidden=true;avatarFallback.hidden=false;};
      avatar.src=picture;
      avatar.alt=name;
    }
    identity.hidden=false;
  }

  function resetDate(value){
    if(!value)return '—';
    const date=new Date(value);
    if(Number.isNaN(date.getTime()))return '—';
    return new Intl.DateTimeFormat('es-MX',{
      timeZone:'UTC',day:'numeric',month:'short',year:'numeric'
    }).format(date)+' UTC';
  }

  function setServiceState(value){
    const el=$('service');
    el.textContent=value||'—';
    el.classList.remove('state-active','state-inactive');
    if(value==='active')el.classList.add('state-active');
    else if(value&&value!=='—')el.classList.add('state-inactive');
  }

  function renderAnswerMeta(result){
    const route=result.route||'';
    const billing=result.billing||{};
    const usage=billing.usage||result.usage||{};
    const tokens=Number(usage.total_tokens ?? usage.totalTokens ?? 0);
    const credits=Number(billing.credit_units_charged ?? 0);
    const memoryContext=result.context_used?.memory===true;

    answerSource.className='route-badge';
    if(route==='memory-exact'){
      answerSource.textContent='Memoria exacta';
      answerSource.classList.add('route-exact');
    }else if(route==='memory-semantic'){
      answerSource.textContent='Memoria semántica';
      answerSource.classList.add('route-semantic');
    }else if(route==='provider'&&memoryContext){
      answerSource.textContent='IA + memoria MCMA';
      answerSource.classList.add('route-ai-memory');
    }else if(route==='provider'){
      const provider=String(result.provider_id||'');
      answerSource.textContent=provider.includes('nova-micro')?'IA · Nova Micro':'IA';
      answerSource.classList.add('route-ai');
    }else{
      answerSource.textContent=route||'Respuesta';
    }

    answerTokens.textContent=number(tokens)+' tokens';
    answerCredits.textContent=number(credits)+' créditos';
    answerTokens.className='metric-badge '+(tokens===0?'zero':'charged');
    answerCredits.className='metric-badge '+(credits===0?'zero':'charged');
    answerRemembered.hidden=result.stored!==true;
    answerMeta.hidden=false;
  }

  async function loadBilling(){
    try{
      const data=await api('/mcma/v1/billing',{method:'GET',headers:{}});
      $('plan').textContent=data.billing.account.plan_id;
      setServiceState(data.billing.account.service_status);
      $('balance').textContent=number(data.billing.available_units);
      $('tokens').textContent=number(data.totals.total_tokens);
      $('spent').textContent=number(data.totals.credit_units_charged);
      const quota=data.billing.quota||{};
      const dailyLimit=Number(quota.daily_requests_limit||0);
      const monthlyLimit=Number(quota.monthly_tokens_limit||0);
      $('requestsToday').textContent=number(quota.daily_requests_used)+' / '+(dailyLimit>0?number(dailyLimit):'∞');
      $('tokensMonth').textContent=number(quota.monthly_tokens_used)+' / '+(monthlyLimit>0?number(monthlyLimit):'∞');
      $('quotaReset').textContent=resetDate(quota.next_reset_at);
    }catch(e){
      $('plan').textContent='—';setServiceState('—');$('balance').textContent='—';
      $('requestsToday').textContent='—';$('tokensMonth').textContent='—';$('quotaReset').textContent='—';
    }
  }

  async function loadKeys(){
    try{
      const data=await api('/mcma/v1/api-keys',{method:'GET',headers:{}});
      apiKeysBox.hidden=false;
      keyList.innerHTML='';
      for(const key of data.keys){
        const row=document.createElement('div');row.className='key-row';
        const text=document.createElement('span');
        text.textContent=key.label+' · '+key.key_id+' · '+key.status;
        row.appendChild(text);
        if(key.status==='active'){
          const btn=document.createElement('button');btn.textContent='Revocar';
          btn.onclick=async()=>{await api('/mcma/v1/api-keys/'+key.key_id,{method:'DELETE',body:'{}'});await loadKeys();};
          row.appendChild(btn);
        }
        keyList.appendChild(row);
      }
    }catch(e){apiKeysBox.hidden=true;}
  }

  async function loadStripe(){
    try{
      const data=await api('/mcma/v1/billing/stripe/packages',{method:'GET',headers:{}});
      stripeBox.hidden=false;stripePackages.innerHTML='';
      for(const pkg of data.packages){
        const card=document.createElement('div');
        const label=document.createElement('strong');label.textContent=pkg.label;
        const details=document.createElement('small');
        const exp=Number(pkg.minor_unit_exponent||0);
        const amount=Number(pkg.amount_minor)/Math.pow(10,exp);
        const mode=pkg.billing_mode==='subscription'?'Suscripción':'Pago único';
        details.textContent=mode+' · '+(pkg.plan_id?('Plan '+pkg.plan_id+' · '):'')+number(pkg.credit_units)+' créditos · '+pkg.currency+' '+amount.toFixed(exp);
        const btn=document.createElement('button');btn.textContent='Comprar';
        btn.onclick=async()=>{
          btn.disabled=true;stripeStatus.textContent='Creando Checkout…';
          try{
            const checkout=await api('/mcma/v1/billing/stripe/checkout',{method:'POST',body:JSON.stringify({package_id:pkg.id})});
            location.href=checkout.checkout.url;
          }catch(e){stripeStatus.textContent=e.message;btn.disabled=false;}
        };
        card.append(label,details,btn);stripePackages.appendChild(card);
      }
      const params=new URLSearchParams(location.search);
      if(params.get('stripe')==='success') stripeStatus.textContent='Pago recibido. El webhook actualizará tus créditos.';
      if(params.get('stripe')==='cancel') stripeStatus.textContent='Pago cancelado.';
    }catch(e){stripeBox.hidden=true;}
  }

  async function detectAdmin(){
    try{await api('/mcma/v1/admin/users',{method:'GET',headers:{}});adminLink.hidden=false;}
    catch(e){adminLink.hidden=true;}
  }

  async function loadMe(){
    setSessionState('pending','Comprobando sesión…');
    try{
      const data=await api('/mcma/v1/me',{method:'GET',headers:{}});
      setSessionState('active','Sesión activa');
      showIdentity(data.identity||{});
      login.hidden=true;logout.hidden=false;registerBox.hidden=true;account.hidden=false;
      $('library').textContent=data.user.library_id;
      form.querySelectorAll('textarea,input,button').forEach(el=>el.disabled=false);
      await Promise.all([loadBilling(),loadKeys(),loadStripe(),detectAdmin()]);
    }catch(error){
      account.hidden=true;apiKeysBox.hidden=true;stripeBox.hidden=true;adminLink.hidden=true;clearIdentity();
      const signedOut=new URLSearchParams(location.search).get('signed_out')==='1';
      if(error.status===401){
        setSessionState('inactive',signedOut?'Sesión cerrada':'Sin sesión');
        login.hidden=false;logout.hidden=true;registerBox.hidden=true;
        if(signedOut)history.replaceState({},'',location.pathname);
      }else if(error.code==='user_not_registered'){
        setSessionState('pending','Usuario autenticado, memoria no registrada');
        login.hidden=true;logout.hidden=false;registerBox.hidden=false;
      }else{
        setSessionState('inactive',error.message);
      }
      form.querySelectorAll('textarea,input,button').forEach(el=>el.disabled=true);
    }
  }

  form.addEventListener('submit',async event=>{
    event.preventDefault();send.disabled=true;answer.textContent='Procesando…';answerMeta.hidden=true;
    try{
      const data=await api('/mcma/v1/ask',{method:'POST',body:JSON.stringify({
        question:$('question').value,current:$('current').checked,remember:$('remember').checked
      })});
      const result=data.result||{};
      answer.textContent=result.answer?.value ?? JSON.stringify(result,null,2);
      renderAnswerMeta(result);
      await loadBilling();
    }catch(error){answer.textContent=error.message;answerMeta.hidden=true;}
    finally{send.disabled=false;}
  });

  register.addEventListener('click',async()=>{
    register.disabled=true;
    try{await api('/mcma/v1/register',{method:'POST',body:'{}'});await loadMe();}
    catch(error){setSessionState('inactive',error.message);}
    finally{register.disabled=false;}
  });

  createKey.addEventListener('click',async()=>{
    const label=prompt('Nombre para esta API key','Mi aplicación');
    if(!label)return;
    try{
      const data=await api('/mcma/v1/api-keys',{method:'POST',body:JSON.stringify({label})});
      newKey.hidden=false;
      newKey.textContent='Guárdala ahora; no volverá a mostrarse:\n'+data.key.token;
      await loadKeys();
    }catch(error){newKey.hidden=false;newKey.textContent=error.message;}
  });

  logout.addEventListener('click',async()=>{
    logout.disabled=true;logout.textContent='Saliendo…';
    setSessionState('pending','Cerrando sesión…');
    try{
      await fetch('logout',{method:'POST',credentials:'same-origin'});
      account.hidden=true;apiKeysBox.hidden=true;stripeBox.hidden=true;adminLink.hidden=true;clearIdentity();
      setSessionState('inactive','Sesión cerrada');
      login.hidden=false;logout.hidden=true;
      form.querySelectorAll('textarea,input,button').forEach(el=>el.disabled=true);
      setTimeout(()=>location.replace('./?signed_out=1'),120);
    }catch(error){
      setSessionState('inactive','No se pudo cerrar la sesión');
      logout.disabled=false;logout.textContent='Salir';
    }
  });

  loadMe();
})();

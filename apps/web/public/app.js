(() => {
  const $ = id => document.getElementById(id);
  const status=$('status'),login=$('login'),logout=$('logout'),adminLink=$('adminLink');
  const account=$('account'),registerBox=$('registerBox'),register=$('register');
  const form=$('askForm'),send=$('send'),answer=$('answer');
  const apiKeysBox=$('apiKeysBox'),createKey=$('createKey'),keyList=$('keyList'),newKey=$('newKey');

  async function api(path,options={}){
    const response=await fetch(path,{credentials:'same-origin',...options,headers:{'Content-Type':'application/json',...(options.headers||{})}});
    const data=await response.json().catch(()=>({}));
    if(!response.ok){const e=new Error(data.message||'Error HTTP '+response.status);e.status=response.status;e.code=data.error;throw e;}
    return data;
  }

  const number=v=>new Intl.NumberFormat().format(Number(v||0));

  async function loadBilling(){
    try{
      const data=await api('/mcma/v1/billing',{method:'GET',headers:{}});
      $('plan').textContent=data.billing.account.plan_id;
      $('service').textContent=data.billing.account.service_status;
      $('balance').textContent=number(data.billing.available_units);
      $('tokens').textContent=number(data.totals.total_tokens);
      $('spent').textContent=number(data.totals.credit_units_charged);
    }catch(e){
      $('plan').textContent='—';$('service').textContent='—';$('balance').textContent='—';
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

  async function detectAdmin(){
    try{await api('/mcma/v1/admin/users',{method:'GET',headers:{}});adminLink.hidden=false;}
    catch(e){adminLink.hidden=true;}
  }

  async function loadMe(){
    try{
      const data=await api('/mcma/v1/me',{method:'GET',headers:{}});
      status.textContent='Sesión activa';login.hidden=true;logout.hidden=false;registerBox.hidden=true;account.hidden=false;
      $('library').textContent=data.user.library_id;
      form.querySelectorAll('textarea,input,button').forEach(el=>el.disabled=false);
      await Promise.all([loadBilling(),loadKeys(),detectAdmin()]);
    }catch(error){
      account.hidden=true;apiKeysBox.hidden=true;adminLink.hidden=true;
      if(error.status===401){status.textContent='Sin sesión';login.hidden=false;logout.hidden=true;registerBox.hidden=true;}
      else if(error.code==='user_not_registered'){status.textContent='Usuario autenticado, memoria no registrada';login.hidden=true;logout.hidden=false;registerBox.hidden=false;}
      else status.textContent=error.message;
      form.querySelectorAll('textarea,input,button').forEach(el=>el.disabled=true);
    }
  }

  form.addEventListener('submit',async event=>{
    event.preventDefault();send.disabled=true;answer.textContent='Procesando…';
    try{
      const data=await api('/mcma/v1/ask',{method:'POST',body:JSON.stringify({
        question:$('question').value,current:$('current').checked,remember:$('remember').checked
      })});
      const result=data.result||{};
      answer.textContent=result.answer?.value ?? JSON.stringify(result,null,2);
      await loadBilling();
    }catch(error){answer.textContent=error.message;}
    finally{send.disabled=false;}
  });

  register.addEventListener('click',async()=>{
    register.disabled=true;
    try{await api('/mcma/v1/register',{method:'POST',body:'{}'});await loadMe();}
    catch(error){status.textContent=error.message;}
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

  logout.addEventListener('click',async()=>{await fetch('/logout',{method:'POST',credentials:'same-origin'});location.href='/';});
  loadMe();
})();

(() => {
  const $ = id => document.getElementById(id);
  const status=$('status'),statusText=$('statusText'),login=$('login'),logout=$('logout'),adminLink=$('adminLink');
  const identity=$('identity'),avatar=$('avatar'),avatarFallback=$('avatarFallback'),identityName=$('identityName'),identityEmail=$('identityEmail');
  const account=$('account'),registerBox=$('registerBox'),register=$('register');
  const form=$('askForm'),send=$('send'),answer=$('answer');
  const answerMeta=$('answerMeta'),answerSource=$('answerSource'),answerTokens=$('answerTokens'),answerCredits=$('answerCredits'),answerRemembered=$('answerRemembered');
  const apiKeysBox=$('apiKeysBox'),createKey=$('createKey'),keyList=$('keyList'),newKey=$('newKey');
  const stripeBox=$('stripeBox'),stripePackages=$('stripePackages'),stripeStatus=$('stripeStatus');
  const memoryExplorer=$('memoryExplorer'),memorySearchForm=$('memorySearchForm'),memoryQuery=$('memoryQuery');
  const memoryTemperature=$('memoryTemperature'),memoryValidation=$('memoryValidation'),memoryReset=$('memoryReset');
  const memoryCount=$('memoryCount'),memoryList=$('memoryList'),memoryPagePrev=$('memoryPagePrev'),memoryPageNext=$('memoryPageNext'),memoryPageLabel=$('memoryPageLabel');
  const memoryDetailEmpty=$('memoryDetailEmpty'),memoryDetailContent=$('memoryDetailContent'),memoryDetailBadges=$('memoryDetailBadges');
  const memoryDetailQuestion=$('memoryDetailQuestion'),memoryDetailAnswer=$('memoryDetailAnswer');
  const memoryDetailValidation=$('memoryDetailValidation'),memoryDetailConfidence=$('memoryDetailConfidence');
  const memoryDetailTemperature=$('memoryDetailTemperature'),memoryDetailFreshness=$('memoryDetailFreshness');
  const memoryDetailCaptured=$('memoryDetailCaptured'),memoryDetailReusable=$('memoryDetailReusable');
  const memoryItemPrev=$('memoryItemPrev'),memoryItemNext=$('memoryItemNext'),memoryConfirm=$('memoryConfirm'),memoryDiscard=$('memoryDiscard'),memoryValidationStatus=$('memoryValidationStatus');
  const memoryState={page:1,limit:20,pages:1,total:0,items:[],selectedId:null};
  const mainTabs=$('mainTabs'),tabButtons=[...document.querySelectorAll('[data-tab-target]')],tabPanels=[...document.querySelectorAll('[data-tab-panel]')];
  const contextPanel=$('contextPanel'),contextRefresh=$('contextRefresh');
  const contextPersistentTotal=$('contextPersistentTotal'),contextReusableTotal=$('contextReusableTotal'),contextGeneratedTotal=$('contextGeneratedTotal'),contextTraceTotal=$('contextTraceTotal');
  const contextLastEmpty=$('contextLastEmpty'),contextLastContent=$('contextLastContent');
  const contextLastQuestion=$('contextLastQuestion'),contextLastRoute=$('contextLastRoute'),contextLastProvider=$('contextLastProvider'),contextLastAt=$('contextLastAt');
  const contextInjectedAnswer=$('contextInjectedAnswer'),contextInjectedMeta=$('contextInjectedMeta');
  const contextGeneratedList=$('contextGeneratedList'),contextSystemList=$('contextSystemList'),contextTraceList=$('contextTraceList');

  async function api(path,options={}){
    const response=await fetch(path,{credentials:'same-origin',...options,headers:{'Content-Type':'application/json',...(options.headers||{})}});
    const data=await response.json().catch(()=>({}));
    if(!response.ok){const e=new Error(data.message||'Error HTTP '+response.status);e.status=response.status;e.code=data.error;throw e;}
    return data;
  }

  const number=v=>new Intl.NumberFormat('es-MX').format(Number(v||0));

  function routeLabel(route){
    return ({
      'memory-exact':'Memoria exacta',
      'memory-semantic':'Memoria semántica',
      'provider':'IA / proveedor',
      'ask':'Sin proveedor'
    })[route]||route||'—';
  }

  function activateTab(name){
    for(const panel of tabPanels)panel.hidden=panel.dataset.tabPanel!==name;
    for(const button of tabButtons){
      const active=button.dataset.tabTarget===name;
      button.classList.toggle('active',active);
      button.setAttribute('aria-selected',active?'true':'false');
    }
    if(name==='memory'&&memoryState.items.length===0)loadMemories(1);
    if(name==='context')loadContext();
  }

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

  function memoryDate(value){
    if(!value)return '—';
    const date=new Date(value);
    if(Number.isNaN(date.getTime()))return '—';
    return new Intl.DateTimeFormat('es-MX',{
      timeZone:'UTC',day:'numeric',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'
    }).format(date)+' UTC';
  }

  function clearMemoryDetail(){
    memoryState.selectedId=null;
    memoryDetailContent.hidden=true;
    memoryDetailEmpty.hidden=false;
    memoryValidationStatus.textContent='';
    for(const node of memoryList.querySelectorAll('.memory-list-item'))node.classList.remove('selected');
  }

  function memoryBadge(text,className='metric-badge'){
    const span=document.createElement('span');
    span.className=className;
    span.textContent=text;
    return span;
  }

  function renderMemoryList(){
    memoryList.replaceChildren();
    if(memoryState.items.length===0){
      const empty=document.createElement('p');
      empty.className='memory-empty';
      empty.textContent=memoryState.total===0?'No hay recuerdos que coincidan con estos filtros.':'No hay recuerdos en esta página.';
      memoryList.appendChild(empty);
    }else{
      for(const item of memoryState.items){
        const button=document.createElement('button');
        button.type='button';
        button.className='memory-list-item';
        button.dataset.memoryId=item.id;
        if(item.id===memoryState.selectedId)button.classList.add('selected');

        const title=document.createElement('strong');
        title.textContent=item.question;

        const meta=document.createElement('span');
        meta.className='memory-list-meta';
        for(const value of [
          item.validation_state,
          Number(item.confidence||0).toFixed(2),
          item.temperature,
          item.reusable?'reutilizable':'requiere revisión'
        ]){
          const tag=document.createElement('span');
          tag.textContent=value;
          meta.appendChild(tag);
        }

        button.append(title,meta);
        button.addEventListener('click',()=>loadMemoryDetail(item.id));
        memoryList.appendChild(button);
      }
    }

    memoryCount.textContent=number(memoryState.total)+' recuerdos · 0 tokens IA';
    memoryPageLabel.textContent=number(memoryState.page)+' / '+number(memoryState.pages);
    memoryPagePrev.disabled=memoryState.page<=1;
    memoryPageNext.disabled=memoryState.page>=memoryState.pages;
  }

  async function loadMemories(page=1){
    const params=new URLSearchParams({
      page:String(page),
      limit:String(memoryState.limit),
      q:memoryQuery.value.trim(),
      temperature:memoryTemperature.value,
      validation:memoryValidation.value
    });
    memoryCount.textContent='Descifrando índice… · 0 tokens IA';
    try{
      const data=await api('/mcma/v1/memories?'+params.toString(),{method:'GET',headers:{}});
      const result=data.memory||{};
      memoryState.page=Number(result.page||1);
      memoryState.pages=Math.max(1,Number(result.pages||1));
      memoryState.total=Number(result.total||0);
      memoryState.items=Array.isArray(result.items)?result.items:[];
      if(memoryState.selectedId&&!memoryState.items.some(item=>item.id===memoryState.selectedId)){
        clearMemoryDetail();
      }
      renderMemoryList();
    }catch(error){
      memoryList.replaceChildren();
      const failed=document.createElement('p');
      failed.className='memory-empty';
      failed.textContent='No se pudo abrir la memoria: '+error.message;
      memoryList.appendChild(failed);
      memoryCount.textContent='Error al leer memoria';
    }
  }

  function renderMemoryDetail(memory){
    memoryState.selectedId=memory.id;
    memoryDetailEmpty.hidden=true;
    memoryDetailContent.hidden=false;
    memoryValidationStatus.textContent='';

    for(const node of memoryList.querySelectorAll('.memory-list-item')){
      node.classList.toggle('selected',node.dataset.memoryId===memory.id);
    }

    memoryDetailQuestion.textContent=memory.question||'—';
    const value=memory.answer?.value;
    memoryDetailAnswer.textContent=typeof value==='string'?value:JSON.stringify(value,null,2);
    memoryDetailValidation.textContent=memory.validation_state||'—';
    memoryDetailConfidence.textContent=Number(memory.confidence||0).toFixed(2);
    memoryDetailTemperature.textContent=memory.temperature||'—';
    memoryDetailFreshness.textContent=(memory.freshness_class||'—')+(memory.stale?' · vencida':'');
    memoryDetailCaptured.textContent=memoryDate(memory.captured_at);
    memoryDetailReusable.textContent=memory.reusable?'Sí · 0 tokens en coincidencia exacta':'No todavía';

    memoryDetailBadges.replaceChildren(
      memoryBadge(memory.validation_state||'—','route-badge '+(memory.validation_state==='verified'?'route-exact':'')),
      memoryBadge('Confianza '+Number(memory.confidence||0).toFixed(2)),
      memoryBadge(memory.temperature||'—'),
      memoryBadge(memory.reusable?'Reutilizable':'Revisión necesaria')
    );

    memoryConfirm.disabled=memory.validation_state==='verified'&&Number(memory.confidence||0)>=0.95;
    memoryDiscard.disabled=memory.validation_state==='retracted';

    const index=memoryState.items.findIndex(item=>item.id===memory.id);
    memoryItemPrev.disabled=index<=0;
    memoryItemNext.disabled=index<0||index>=memoryState.items.length-1;
  }

  async function loadMemoryDetail(id){
    memoryValidationStatus.textContent='Descifrando respuesta…';
    try{
      const data=await api('/mcma/v1/memories/'+id,{method:'GET',headers:{}});
      renderMemoryDetail(data.memory||{});
    }catch(error){
      memoryValidationStatus.textContent='No se pudo descifrar: '+error.message;
    }
  }

  async function validateMemory(action){
    if(!memoryState.selectedId)return;
    const button=action==='confirm'?memoryConfirm:memoryDiscard;
    button.disabled=true;
    memoryValidationStatus.textContent=action==='confirm'?'Confirmando sin IA…':'Descartando sin IA…';
    try{
      const data=await api('/mcma/v1/memories/'+memoryState.selectedId+'/validation',{
        method:'POST',
        body:JSON.stringify({action})
      });
      renderMemoryDetail(data.memory||{});
      memoryValidationStatus.textContent=(data.validation?.unchanged?'Sin cambios':'Memoria actualizada')+' · 0 tokens IA · 0 créditos';
      await loadMemories(memoryState.page);
    }catch(error){
      memoryValidationStatus.textContent=error.message;
      button.disabled=false;
    }
  }

  function selectMemoryRelative(delta){
    const index=memoryState.items.findIndex(item=>item.id===memoryState.selectedId);
    const next=index+delta;
    if(index<0||next<0||next>=memoryState.items.length)return;
    loadMemoryDetail(memoryState.items[next].id);
  }

  function contextListItem(title,meta,click=null){
    const el=document.createElement(click?'button':'div');
    if(click)el.type='button';
    el.className='context-list-item';
    const strong=document.createElement('strong');
    strong.textContent=title;
    const small=document.createElement('small');
    small.textContent=meta;
    el.append(strong,small);
    if(click)el.addEventListener('click',click);
    return el;
  }

  function renderContextTrace(trace){
    if(!trace){
      contextLastEmpty.hidden=false;
      contextLastContent.hidden=true;
      return;
    }
    contextLastEmpty.hidden=true;
    contextLastContent.hidden=false;
    contextLastQuestion.textContent=trace.question||'—';
    contextLastRoute.textContent=routeLabel(trace.route);
    contextLastProvider.textContent=trace.provider_id||((trace.provider_called===false)?'No se llamó a IA':'—');
    contextLastAt.textContent=memoryDate(trace.at);

    const used=trace.context_used||{};
    if(used.memory===true){
      const value=used.answer?.value;
      contextInjectedAnswer.textContent=typeof value==='string'?value:(value!==undefined?JSON.stringify(value,null,2):'Memoria usada, pero esta traza antigua no contiene el texto inyectado.');
      contextInjectedMeta.replaceChildren(
        memoryBadge('MCMA inyectada','route-badge route-ai-memory'),
        memoryBadge(used.validation_state||'—'),
        memoryBadge('Confianza '+Number(used.confidence||0).toFixed(2)),
        memoryBadge(used.freshness_class||'—')
      );
    }else{
      if(trace.route==='memory-exact'||trace.route==='memory-semantic'){
        contextInjectedAnswer.textContent='No se inyectó memoria en un modelo: MCMA respondió directamente desde '+routeLabel(trace.route).toLowerCase()+'.';
      }else{
        contextInjectedAnswer.textContent='Ninguna memoria persistente fue inyectada al modelo en esta pregunta.';
      }
      contextInjectedMeta.replaceChildren(memoryBadge('Sin contexto inyectado'));
    }

    const billing=trace.billing||{};
    const usage=billing.usage||{};
    const total=Number(usage.total_tokens??usage.totalTokens??0);
    contextInjectedMeta.append(
      memoryBadge(number(total)+' tokens'),
      memoryBadge(number(billing.credit_units_charged||0)+' créditos')
    );

    for(const node of contextTraceList.querySelectorAll('.context-list-item')){
      node.classList.toggle('selected',node.dataset.traceId===trace.trace_id);
    }
  }

  function openGeneratedMemory(item){
    const match=String(item.logical_ref||'').match(/q-([0-9a-f]{64})$/);
    if(!match)return;
    activateTab('memory');
    memoryQuery.value=item.question||'';
    memoryTemperature.value='all';
    memoryValidation.value='all';
    loadMemories(1).then(()=>loadMemoryDetail(match[1]));
  }

  function renderContext(data){
    const summary=data.summary||{};
    const traces=Array.isArray(data.traces)?data.traces:[];
    const generated=Array.isArray(data.generated_memories)?data.generated_memories:[];
    const systemObjects=Array.isArray(data.system_objects)?data.system_objects:[];

    contextPersistentTotal.textContent=number(summary.total||0);
    contextReusableTotal.textContent=number(summary.reusable||0);
    contextGeneratedTotal.textContent=number(summary.generated_by_model||0);
    contextTraceTotal.textContent=number(traces.length);

    contextGeneratedList.replaceChildren();
    if(generated.length===0){
      const empty=document.createElement('div');empty.className='context-empty';empty.textContent='Todavía no hay memorias persistentes generadas por IA.';contextGeneratedList.appendChild(empty);
    }else{
      for(const item of generated){
        contextGeneratedList.appendChild(contextListItem(
          item.question||'Memoria',
          (item.provider_id||'modelo')+' · '+(item.validation_state||'—')+' · '+Number(item.confidence||0).toFixed(2)+' · '+(item.temperature||'—'),
          ()=>openGeneratedMemory(item)
        ));
      }
    }

    contextSystemList.replaceChildren();
    if(systemObjects.length===0){
      const empty=document.createElement('div');empty.className='context-empty';empty.textContent='No hay objetos internos visibles.';contextSystemList.appendChild(empty);
    }else{
      for(const item of systemObjects){
        const el=contextListItem(item.logical_ref||'memory://system',''+(item.cognitive_layer||'—')+' · '+(item.temperature||'—')+' · '+(item.scope||'—'));
        el.querySelector('strong').classList.add('context-object-ref');
        contextSystemList.appendChild(el);
      }
    }

    contextTraceList.replaceChildren();
    if(traces.length===0){
      const empty=document.createElement('div');empty.className='context-empty';empty.textContent='Aún no hay preguntas trazadas.';contextTraceList.appendChild(empty);
    }else{
      for(const trace of traces){
        const el=contextListItem(
          trace.question||'Pregunta',
          memoryDate(trace.at)+' · '+routeLabel(trace.route)+(trace.context_used?.memory===true?' · contexto MCMA':''),
          ()=>renderContextTrace(trace)
        );
        el.dataset.traceId=trace.trace_id||'';
        contextTraceList.appendChild(el);
      }
    }
    renderContextTrace(traces[0]||null);
  }

  async function loadContext(){
    contextRefresh.disabled=true;
    try{
      const data=await api('/mcma/v1/context',{method:'GET',headers:{}});
      renderContext(data.context||{});
    }catch(error){
      contextLastEmpty.hidden=false;
      contextLastContent.hidden=true;
      contextLastEmpty.textContent='No se pudo leer el contexto: '+error.message;
    }finally{
      contextRefresh.disabled=false;
    }
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
      login.hidden=true;logout.hidden=false;registerBox.hidden=true;account.hidden=false;mainTabs.hidden=false;
      $('library').textContent=data.user.library_id;
      form.querySelectorAll('textarea,input,button').forEach(el=>el.disabled=false);
      activateTab('ask');
      await Promise.all([loadBilling(),loadKeys(),loadStripe(),detectAdmin()]);
    }catch(error){
      account.hidden=true;apiKeysBox.hidden=true;stripeBox.hidden=true;mainTabs.hidden=true;memoryExplorer.hidden=true;contextPanel.hidden=true;adminLink.hidden=true;clearIdentity();
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
      if(result.stored===true)await loadMemories(1);
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

  memorySearchForm.addEventListener('submit',event=>{
    event.preventDefault();
    clearMemoryDetail();
    loadMemories(1);
  });
  memoryReset.addEventListener('click',()=>{
    memoryQuery.value='';
    memoryTemperature.value='all';
    memoryValidation.value='all';
    clearMemoryDetail();
    loadMemories(1);
  });
  memoryTemperature.addEventListener('change',()=>{clearMemoryDetail();loadMemories(1);});
  memoryValidation.addEventListener('change',()=>{clearMemoryDetail();loadMemories(1);});
  memoryPagePrev.addEventListener('click',()=>{if(memoryState.page>1){clearMemoryDetail();loadMemories(memoryState.page-1);}});
  memoryPageNext.addEventListener('click',()=>{if(memoryState.page<memoryState.pages){clearMemoryDetail();loadMemories(memoryState.page+1);}});
  memoryItemPrev.addEventListener('click',()=>selectMemoryRelative(-1));
  memoryItemNext.addEventListener('click',()=>selectMemoryRelative(1));
  memoryConfirm.addEventListener('click',()=>validateMemory('confirm'));
  memoryDiscard.addEventListener('click',()=>validateMemory('discard'));

  logout.addEventListener('click',async()=>{
    logout.disabled=true;logout.textContent='Saliendo…';
    setSessionState('pending','Cerrando sesión…');
    try{
      await fetch('logout',{method:'POST',credentials:'same-origin'});
      account.hidden=true;apiKeysBox.hidden=true;stripeBox.hidden=true;mainTabs.hidden=true;memoryExplorer.hidden=true;contextPanel.hidden=true;adminLink.hidden=true;clearIdentity();
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

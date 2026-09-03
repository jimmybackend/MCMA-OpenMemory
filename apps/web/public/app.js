(() => {
  const $ = id => document.getElementById(id);
  const status=$('status'),statusText=$('statusText'),login=$('login'),logout=$('logout'),adminLink=$('adminLink');
  const identity=$('identity'),avatar=$('avatar'),avatarFallback=$('avatarFallback'),identityName=$('identityName'),identityEmail=$('identityEmail');
  const account=$('account'),registerBox=$('registerBox'),register=$('register');
  const form=$('askForm'),send=$('send'),answer=$('answer');
  const answerMeta=$('answerMeta'),answerSource=$('answerSource'),answerTokens=$('answerTokens'),answerCredits=$('answerCredits'),answerRemembered=$('answerRemembered');
  const apiKeysBox=$('apiKeysBox'),createKey=$('createKey'),keyList=$('keyList'),newKey=$('newKey');
  const stripeBox=$('stripeBox'),stripePackages=$('stripePackages'),stripeStatus=$('stripeStatus');
  const accountDrawer=$('accountDrawer'),accountDrawerContent=$('accountDrawerContent');
  const memoryExplorer=$('memoryExplorer'),memorySearchForm=$('memorySearchForm'),memoryQuery=$('memoryQuery');
  const memoryTemperature=$('memoryTemperature'),memoryValidation=$('memoryValidation'),memoryReset=$('memoryReset');
  const memoryCount=$('memoryCount'),memoryList=$('memoryList'),memoryPagePrev=$('memoryPagePrev'),memoryPageNext=$('memoryPageNext'),memoryPageLabel=$('memoryPageLabel');
  const memoryTreeView=$('memoryTreeView'),memoryListView=$('memoryListView'),memoryTreeViewPanel=$('memoryTreeViewPanel'),memoryListViewPanel=$('memoryListViewPanel'),memoryTree=$('memoryTree');
  const memoryTreeDetailEmpty=$('memoryTreeDetailEmpty'),memoryTreeDetailContent=$('memoryTreeDetailContent'),memoryTreeDetailBadges=$('memoryTreeDetailBadges');
  const memoryTreeDetailTitle=$('memoryTreeDetailTitle'),memoryTreeDetailPath=$('memoryTreeDetailPath'),memoryTreeDetailAnswer=$('memoryTreeDetailAnswer');
  const memoryTreeSourceWrap=$('memoryTreeSourceWrap'),memoryTreeDetailSource=$('memoryTreeDetailSource');
  const memoryTreeDetailLayer=$('memoryTreeDetailLayer'),memoryTreeDetailScope=$('memoryTreeDetailScope'),memoryTreeDetailTemperature=$('memoryTreeDetailTemperature');
  const memoryTreeDetailMaturity=$('memoryTreeDetailMaturity'),memoryTreeDetailRevision=$('memoryTreeDetailRevision'),memoryTreeDetailUpdated=$('memoryTreeDetailUpdated');
  const memoryTreeDetailObject=$('memoryTreeDetailObject'),memoryTreeDetailHash=$('memoryTreeDetailHash');
  const libraryAnswerLabel=$('libraryAnswerLabel'),librarySourceLabel=$('librarySourceLabel'),libraryCatalogWrap=$('libraryCatalogWrap'),libraryCatalogBadges=$('libraryCatalogBadges');
  const canonicalMemoryActions=$('canonicalMemoryActions'),memoryUpdateInChat=$('memoryUpdateInChat'),memoryUpdateInChatStatus=$('memoryUpdateInChatStatus');
  const libraryInlineEditActions=$('libraryInlineEditActions'),libraryInlineEditOpen=$('libraryInlineEditOpen'),libraryInlineEditStatus=$('libraryInlineEditStatus');
  const libraryInlineEditForm=$('libraryInlineEditForm'),libraryInlineEditText=$('libraryInlineEditText'),libraryInlineEditSave=$('libraryInlineEditSave'),libraryInlineEditCancel=$('libraryInlineEditCancel');
  const interactionActions=$('interactionActions'),interactionApprove=$('interactionApprove'),interactionDiscard=$('interactionDiscard'),interactionValidationStatus=$('interactionValidationStatus');
  const conversationLabel=$('conversationLabel'),newConversation=$('newConversation');
  const conversationSearch=$('conversationSearch'),conversationList=$('conversationList'),conversationProjectsWrap=$('conversationProjectsWrap'),conversationProjects=$('conversationProjects');
  const conversationTitle=$('conversationTitle'),conversationReadCost=$('conversationReadCost'),conversationSidebarToggle=$('conversationSidebarToggle'),chatWorkspace=$('askPanel'),chatMessages=$('chatMessages');
  const composerStatus=$('composerStatus'),questionInput=$('question');
  const memoryEditTarget=$('memoryEditTarget'),memoryEditTargetTitle=$('memoryEditTargetTitle'),memoryEditTargetRef=$('memoryEditTargetRef'),memoryEditTargetClear=$('memoryEditTargetClear');
  const conversationState={items:[],filter:'',loading:false};
  const memoryDetailEmpty=$('memoryDetailEmpty'),memoryDetailContent=$('memoryDetailContent'),memoryDetailBadges=$('memoryDetailBadges');
  const memoryDetailQuestion=$('memoryDetailQuestion'),memoryDetailAnswer=$('memoryDetailAnswer');
  const memoryDetailValidation=$('memoryDetailValidation'),memoryDetailConfidence=$('memoryDetailConfidence');
  const memoryDetailTemperature=$('memoryDetailTemperature'),memoryDetailFreshness=$('memoryDetailFreshness');
  const memoryDetailCaptured=$('memoryDetailCaptured'),memoryDetailReusable=$('memoryDetailReusable');
  const memoryItemPrev=$('memoryItemPrev'),memoryItemNext=$('memoryItemNext'),memoryConfirm=$('memoryConfirm'),memoryDiscard=$('memoryDiscard'),memoryValidationStatus=$('memoryValidationStatus');
  const memoryInlineEditOpen=$('memoryInlineEditOpen'),memoryInlineEditForm=$('memoryInlineEditForm'),memoryInlineEditText=$('memoryInlineEditText'),memoryInlineEditSave=$('memoryInlineEditSave'),memoryInlineEditCancel=$('memoryInlineEditCancel');
  const memoryState={
    page:1,limit:20,pages:1,total:0,items:[],selectedId:null,mode:'tree',tree:null,treeTotal:0,
    selectedRef:null,selectedKind:null,selectedEditableText:'',
    selectedListRef:null,selectedListEditableText:''
  };
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

  function randomHex(bytes=16){
    const data=new Uint8Array(bytes);
    crypto.getRandomValues(data);
    return [...data].map(v=>v.toString(16).padStart(2,'0')).join('');
  }

  function setConversationId(id){
    if(!/^conv_[0-9a-f]{32}$/.test(id))return '';
    try{sessionStorage.setItem('mcma_conversation_id',id);}catch(error){}
    conversationLabel.textContent=id.slice(0,13)+'…'+id.slice(-8);
    conversationLabel.title=id;
    return id;
  }

  function createConversationId(){
    return setConversationId('conv_'+randomHex(16));
  }

  function currentConversationId(){
    let id='';
    try{id=sessionStorage.getItem('mcma_conversation_id')||'';}catch(error){}
    if(!/^conv_[0-9a-f]{32}$/.test(id))return createConversationId();
    return setConversationId(id);
  }

  const memoryEditTargetKey='mcma_memory_edit_target_v1';

  function getMemoryEditTarget(){
    try{
      const value=JSON.parse(sessionStorage.getItem(memoryEditTargetKey)||'null');
      if(
        value
        &&/^conv_[0-9a-f]{32}$/.test(value.conversation_id||'')
        &&typeof value.ref==='string'
        &&value.ref.startsWith('memory://user/')
      )return value;
    }catch(error){}
    return null;
  }

  function renderMemoryEditTarget(conversationId){
    const target=getMemoryEditTarget();
    const active=target&&target.conversation_id===conversationId;
    memoryEditTarget.hidden=!active;
    if(!active){
      memoryEditTargetTitle.textContent='—';
      memoryEditTargetRef.textContent='—';
      return null;
    }
    memoryEditTargetTitle.textContent=target.title||'Memoria personal';
    memoryEditTargetRef.textContent=target.ref;
    memoryEditTargetRef.title=target.ref;
    return target;
  }

  function setMemoryEditTarget(ref,title,conversationId){
    if(!ref.startsWith('memory://user/')||!/^conv_[0-9a-f]{32}$/.test(conversationId))return null;
    const value={ref,title:String(title||'Memoria personal'),conversation_id:conversationId};
    try{sessionStorage.setItem(memoryEditTargetKey,JSON.stringify(value));}catch(error){}
    renderMemoryEditTarget(conversationId);
    return value;
  }

  function clearMemoryEditTarget(){
    try{sessionStorage.removeItem(memoryEditTargetKey);}catch(error){}
    memoryEditTarget.hidden=true;
    memoryEditTargetTitle.textContent='—';
    memoryEditTargetRef.textContent='—';
  }

  const pendingRequestKey='mcma_pending_request_v1';

  function setPendingRequest(value){
    try{sessionStorage.setItem(pendingRequestKey,JSON.stringify(value));}catch(error){}
  }

  function getPendingRequest(){
    try{
      const value=JSON.parse(sessionStorage.getItem(pendingRequestKey)||'null');
      if(value&&/^req_[0-9a-f]{32}$/.test(value.request_id||'')&&/^conv_[0-9a-f]{32}$/.test(value.conversation_id||''))return value;
    }catch(error){}
    return null;
  }

  function clearPendingRequest(requestId=null){
    const current=getPendingRequest();
    if(requestId&&current&&current.request_id!==requestId)return;
    try{sessionStorage.removeItem(pendingRequestKey);}catch(error){}
  }

  const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));

  async function recoverRequest(requestId,conversationId,maxAttempts=45){
    for(let attempt=0;attempt<maxAttempts;attempt++){
      try{
        const data=await api('/mcma/v1/requests/'+encodeURIComponent(requestId)+'?conversation_id='+encodeURIComponent(conversationId),{method:'GET',headers:{}});
        if(data.status==='completed'&&data.result)return data.result;
      }catch(error){
        if(error.status===401||error.status===403||error.status===400)throw error;
      }
      await sleep(2000);
    }
    return null;
  }

  async function recoverStoredPendingRequest(){
    const pending=getPendingRequest();
    if(!pending)return;
    composerStatus.textContent='Recuperando una respuesta que quedó procesándose…';
    const recovered=await recoverRequest(pending.request_id,pending.conversation_id,15);
    if(recovered){
      clearPendingRequest(pending.request_id);
      setConversationId(pending.conversation_id);
      await loadConversations({openCurrent:true});
      composerStatus.textContent='Respuesta recuperada del archivo cifrado después de la interrupción.';
    }else{
      composerStatus.textContent='La respuesta sigue procesándose. MCMA volverá a comprobarla cuando recargues o continúes en esta sesión.';
    }
  }

  function prepareAccountDrawer(){
    if(!accountDrawerContent)return;
    for(const panel of [account,stripeBox,apiKeysBox]){
      if(panel&&panel.parentElement!==accountDrawerContent)accountDrawerContent.appendChild(panel);
    }
  }

  function normalizedSearch(value){
    return String(value||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
  }

  function conversationGroup(value){
    const date=new Date(value||'');
    if(Number.isNaN(date.getTime()))return 'Anteriores';
    const now=new Date();
    const today=new Date(now.getFullYear(),now.getMonth(),now.getDate());
    const day=new Date(date.getFullYear(),date.getMonth(),date.getDate());
    const days=Math.floor((today-day)/86400000);
    if(days<=0)return 'Hoy';
    if(days===1)return 'Ayer';
    if(days<=7)return 'Últimos 7 días';
    return 'Anteriores';
  }

  function conversationDate(value){
    const date=new Date(value||'');
    if(Number.isNaN(date.getTime()))return '—';
    return new Intl.DateTimeFormat('es-MX',{day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'}).format(date);
  }

  function shortConversationTitle(value){
    const text=String(value||'').replace(/\s+/g,' ').trim();
    if(text==='')return 'Nueva conversación';
    return text.length<=72?text:text.slice(0,69).trimEnd()+'…';
  }

  function displayChatValue(value){
    if(typeof value==='string')return value;
    if(value===null||value===undefined)return '';
    try{return JSON.stringify(value,null,2);}catch(error){return String(value);}
  }

  function clearChatMessages(){
    chatMessages.replaceChildren();
  }

  function renderChatEmpty(){
    clearChatMessages();
    const wrap=document.createElement('div');
    wrap.className='chat-empty-state';
    const mark=document.createElement('span');
    mark.className='chat-empty-mark';
    mark.setAttribute('aria-hidden','true');
    mark.textContent='M';
    const title=document.createElement('h3');
    title.textContent='¿Qué quieres recordar o preguntar?';
    const copy=document.createElement('p');
    copy.textContent='MCMA puede responder, recuperar memoria y guardar esta conversación cifrada en tu biblioteca.';
    wrap.append(mark,title,copy);
    chatMessages.appendChild(wrap);
  }

  let activeSpeechButton=null;

  function splitSpeechText(text,maxLength=180){
    const sentences=String(text||'').replace(/\s+/g,' ').match(/[^.!?]+[.!?]+|[^.!?]+$/g)||[];
    const chunks=[];
    for(const raw of sentences){
      let sentence=raw.trim();
      while(sentence.length>maxLength){
        let cut=sentence.lastIndexOf(' ',maxLength);
        if(cut<40)cut=maxLength;
        chunks.push(sentence.slice(0,cut).trim());
        sentence=sentence.slice(cut).trim();
      }
      if(sentence)chunks.push(sentence);
    }
    return chunks;
  }

  function stopChatSpeech(){
    if('speechSynthesis' in window)window.speechSynthesis.cancel();
    if(activeSpeechButton){
      activeSpeechButton.textContent='Leer';
      activeSpeechButton.setAttribute('aria-label','Leer respuesta en voz alta');
      activeSpeechButton=null;
    }
  }

  function speakChatContent(content,button){
    if(!('speechSynthesis' in window))return;
    const text=content.textContent.trim();
    if(text==='')return;
    if(activeSpeechButton===button){
      stopChatSpeech();
      return;
    }

    stopChatSpeech();
    const queue=splitSpeechText(text);
    if(queue.length===0)return;
    let index=0;
    activeSpeechButton=button;
    button.textContent='Detener';
    button.setAttribute('aria-label','Detener lectura en voz alta');

    const finish=()=>{
      if(activeSpeechButton===button){
        button.textContent='Leer';
        button.setAttribute('aria-label','Leer respuesta en voz alta');
        activeSpeechButton=null;
      }
    };

    const next=()=>{
      if(activeSpeechButton!==button)return;
      if(index>=queue.length){
        finish();
        return;
      }
      const utterance=new SpeechSynthesisUtterance(queue[index]);
      utterance.lang=navigator.language||'es-MX';
      utterance.onend=()=>{index+=1;next();};
      utterance.onerror=finish;
      window.speechSynthesis.speak(utterance);
    };
    next();
  }

  async function copyChatContent(content,button){
    const text=content.textContent;
    if(text.trim()==='')return;
    let copied=false;
    try{
      if(navigator.clipboard&&typeof navigator.clipboard.writeText==='function'){
        await navigator.clipboard.writeText(text);
        copied=true;
      }
    }catch(error){}

    if(!copied){
      const fallback=document.createElement('textarea');
      fallback.value=text;
      fallback.setAttribute('readonly','');
      fallback.style.position='fixed';
      fallback.style.opacity='0';
      document.body.appendChild(fallback);
      fallback.select();
      try{copied=document.execCommand('copy');}catch(error){copied=false;}
      fallback.remove();
    }

    const original=button.textContent;
    button.textContent=copied?'Copiado':'No se pudo copiar';
    setTimeout(()=>{button.textContent=original;},1200);
  }

  function appendChatMessage(role,text,meta=[]){
    const article=document.createElement('article');
    article.className='chat-message '+(role==='user'?'chat-message-user':'chat-message-assistant');
    const roleNode=document.createElement('div');
    roleNode.className='chat-message-role';
    roleNode.textContent=role==='user'?'Tú':'MCMA';
    const content=document.createElement('div');
    content.className='chat-message-content';
    content.textContent=displayChatValue(text);
    const metaNode=document.createElement('div');
    metaNode.className='chat-message-meta';
    for(const value of meta){
      if(value===null||value===undefined||String(value).trim()==='')continue;
      const item=document.createElement('span');
      item.textContent=String(value);
      metaNode.appendChild(item);
    }

    let actions=null;
    if(role!=='user'){
      actions=document.createElement('div');
      actions.className='chat-message-actions';

      const copyButton=document.createElement('button');
      copyButton.type='button';
      copyButton.className='chat-message-action';
      copyButton.textContent='Copiar';
      copyButton.setAttribute('aria-label','Copiar respuesta');
      copyButton.addEventListener('click',()=>copyChatContent(content,copyButton));

      const speakButton=document.createElement('button');
      speakButton.type='button';
      speakButton.className='chat-message-action';
      speakButton.textContent='Leer';
      speakButton.setAttribute('aria-label','Leer respuesta en voz alta');
      if(!('speechSynthesis' in window)){
        speakButton.disabled=true;
        speakButton.title='Este navegador no soporta lectura en voz alta.';
      }else{
        speakButton.addEventListener('click',()=>speakChatContent(content,speakButton));
      }

      actions.append(copyButton,speakButton);
    }

    article.append(roleNode,content);
    if(actions)article.appendChild(actions);
    if(metaNode.childElementCount>0)article.appendChild(metaNode);
    chatMessages.appendChild(article);
    chatMessages.scrollTop=chatMessages.scrollHeight;
    return {article,content,meta:metaNode};
  }

  function setChatMessageMeta(message,values=[]){
    message.meta.replaceChildren();
    for(const value of values){
      if(value===null||value===undefined||String(value).trim()==='')continue;
      const item=document.createElement('span');
      item.textContent=String(value);
      message.meta.appendChild(item);
    }
    if(message.meta.childElementCount>0&&!message.meta.isConnected)message.article.appendChild(message.meta);
  }

  function usageMeta(route,billing,validation=null){
    const usage=billing&&typeof billing.usage==='object'?billing.usage:{};
    const total=Number(billing?.total_tokens??usage.total_tokens??usage.totalTokens??0);
    const input=Number(usage.input_tokens??usage.inputTokens??0);
    const output=Number(usage.output_tokens??usage.outputTokens??0);
    const embedding=Number(usage.embedding_tokens??0);
    const calls=Number(usage.model_calls??0);
    const credits=Number(billing?.credit_units_charged??0);
    const meta=[routeLabel(route),number(total)+' tokens',number(credits)+' créditos'];
    if(input>0)meta.push('entrada '+number(input));
    if(output>0)meta.push('salida '+number(output));
    if(embedding>0)meta.push('embedding '+number(embedding));
    if(calls>0)meta.push(number(calls)+' llamada'+(calls===1?'':'s')+' IA');
    if(validation?.state)meta.push('Estado: '+validation.state);
    return meta;
  }

  function interactionMeta(interaction){
    const billing=interaction.billing&&typeof interaction.billing==='object'?interaction.billing:{};
    const validation=interaction.validation&&typeof interaction.validation==='object'?interaction.validation:{};
    return usageMeta(interaction.route,billing,validation);
  }

  function resultMessageMeta(result){
    const billing=result.billing&&typeof result.billing==='object'?result.billing:{usage:result.usage||{}};
    return usageMeta(result.route,billing);
  }

  function renderConversationProjects(){
    conversationProjects.replaceChildren();
    const projects=[];
    for(const item of conversationState.items){
      for(const project of Array.isArray(item.projects)?item.projects:[]){
        if(typeof project==='string'&&project.trim()!==''&&!projects.includes(project))projects.push(project);
      }
    }
    projects.sort((a,b)=>a.localeCompare(b,'es'));
    conversationProjectsWrap.hidden=projects.length===0;
    for(const project of projects.slice(0,16)){
      const button=document.createElement('button');
      button.type='button';
      button.className='conversation-project';
      button.textContent=project;
      button.title='Filtrar por '+project;
      button.addEventListener('click',()=>{
        conversationState.filter=project;
        conversationSearch.value=project;
        renderConversationList();
      });
      conversationProjects.appendChild(button);
    }
  }

  function renderConversationList(){
    conversationList.replaceChildren();
    const filter=normalizedSearch(conversationState.filter);
    const current=currentConversationId();
    const filtered=conversationState.items.filter(item=>{
      if(filter==='')return true;
      return normalizedSearch([
        item.title||'',
        item.conversation_id||'',
        ...(Array.isArray(item.projects)?item.projects:[])
      ].join(' ')).includes(filter);
    });

    if(filtered.length===0){
      const empty=document.createElement('div');
      empty.className='conversation-empty';
      empty.textContent=filter===''?'Todavía no hay conversaciones guardadas.':'No hay conversaciones que coincidan.';
      conversationList.appendChild(empty);
      return;
    }

    const order=['Hoy','Ayer','Últimos 7 días','Anteriores'];
    const grouped=new Map(order.map(label=>[label,[]]));
    for(const item of filtered)grouped.get(conversationGroup(item.last_at)).push(item);

    for(const label of order){
      const items=grouped.get(label);
      if(items.length===0)continue;
      const group=document.createElement('section');
      group.className='conversation-group';
      const heading=document.createElement('div');
      heading.className='conversation-group-title';
      heading.textContent=label;
      group.appendChild(heading);

      for(const item of items){
        const button=document.createElement('button');
        button.type='button';
        button.className='conversation-item';
        button.classList.toggle('active',item.conversation_id===current);
        button.dataset.conversationId=item.conversation_id;

        const title=document.createElement('span');
        title.className='conversation-item-title';
        title.textContent=item.title||'Conversación';

        const meta=document.createElement('span');
        meta.className='conversation-item-meta';
        const count=document.createElement('span');
        count.textContent=number(item.interaction_count||0)+' turnos';
        const at=document.createElement('span');
        at.textContent=conversationDate(item.last_at);
        meta.append(count,at);

        button.append(title,meta);
        button.addEventListener('click',()=>loadConversation(item.conversation_id));
        group.appendChild(button);
      }
      conversationList.appendChild(group);
    }
  }

  function markActiveConversation(){
    const current=currentConversationId();
    for(const button of conversationList.querySelectorAll('[data-conversation-id]')){
      button.classList.toggle('active',button.dataset.conversationId===current);
    }
  }

  function renderNewConversation(clearInput=true){
    conversationTitle.textContent='Nueva conversación';
    answerMeta.hidden=true;
    answer.textContent='';
    composerStatus.textContent='Listo · el historial visible no se reinjecta automáticamente al modelo.';
    if(clearInput)questionInput.value='';
    renderChatEmpty();
    renderConversationList();
  }

  function startNewConversation(){
    clearMemoryEditTarget();
    createConversationId();
    renderNewConversation(true);
    chatWorkspace.classList.remove('sidebar-open');
    conversationSidebarToggle.setAttribute('aria-expanded','false');
    questionInput.focus();
  }

  async function loadConversations({openCurrent=true}={}){
    conversationState.loading=true;
    if(conversationList.childElementCount===0){
      const loading=document.createElement('div');
      loading.className='conversation-empty';
      loading.textContent='Cargando archivo…';
      conversationList.appendChild(loading);
    }
    try{
      const data=await api('/mcma/v1/conversations',{method:'GET',headers:{}});
      const archive=data.archive||{};
      conversationState.items=Array.isArray(archive.conversations)?archive.conversations:[];
      conversationState.items.sort((a,b)=>(Date.parse(b.last_at)||0)-(Date.parse(a.last_at)||0));
      renderConversationProjects();
      renderConversationList();

      if(openCurrent){
        const current=currentConversationId();
        const exists=conversationState.items.some(item=>item.conversation_id===current);
        if(exists)await loadConversation(current,{refreshList:false});
        else renderNewConversation(false);
      }
    }catch(error){
      conversationList.replaceChildren();
      const failed=document.createElement('div');
      failed.className='conversation-empty';
      failed.textContent='No se pudo abrir el archivo: '+error.message;
      conversationList.appendChild(failed);
      composerStatus.textContent='No se pudo leer el historial. Enviar sigue disponible si la sesión está activa.';
    }finally{
      conversationState.loading=false;
    }
  }

  async function loadConversation(conversationId,{refreshList=true}={}){
    if(!/^conv_[0-9a-f]{32}$/.test(conversationId))return;
    composerStatus.textContent='Abriendo conversación cifrada… · 0 tokens IA';
    try{
      const data=await api('/mcma/v1/conversations/'+encodeURIComponent(conversationId),{method:'GET',headers:{}});
      const archive=data.archive||{};
      const summary=archive.conversation||{};
      const interactions=Array.isArray(archive.interactions)?archive.interactions:[];
      setConversationId(conversationId);
      renderMemoryEditTarget(conversationId);
      conversationTitle.textContent=summary.title||'Conversación';
      clearChatMessages();

      for(const interaction of interactions){
        appendChatMessage('user',interaction.question||'');
        appendChatMessage('assistant',interaction.answer?.value,interactionMeta(interaction));
      }
      if(interactions.length===0)renderChatEmpty();

      conversationReadCost.textContent='0 tokens IA · 0 créditos';
      composerStatus.textContent='Historial abierto · 0 tokens IA al navegar · la IA seleccionará sólo contexto reciente/relevante si necesita generar.';
      if(refreshList)renderConversationList();
      else markActiveConversation();
      chatWorkspace.classList.remove('sidebar-open');
      conversationSidebarToggle.setAttribute('aria-expanded','false');
      questionInput.focus();
    }catch(error){
      composerStatus.textContent='No se pudo abrir la conversación: '+error.message;
    }
  }

  function routeLabel(route){
    return ({
      'memory-exact':'Memoria exacta',
      'memory-semantic':'Memoria semántica',
      'memory-capture':'Memoria guardada',
      'memory-mutation':'Memoria modificada',
      'provider':'IA / proveedor',
      'ask':'Sin proveedor'
    })[route]||route||'—';
  }

  function activateTab(name){
    const valid=tabButtons.some(button=>button.dataset.tabTarget===name);
    if(!valid)name='ask';

    for(const panel of tabPanels){
      const active=panel.dataset.tabPanel===name;
      panel.hidden=!active;
      panel.setAttribute('aria-hidden',active?'false':'true');
    }

    for(const button of tabButtons){
      const active=button.dataset.tabTarget===name;
      button.classList.toggle('active',active);
      button.setAttribute('aria-selected',active?'true':'false');
      button.tabIndex=active?0:-1;
    }

    if(name==='memory'){
      if(memoryState.mode==='tree'&&memoryState.tree===null)loadMemoryTree();
      if(memoryState.mode==='list'&&memoryState.items.length===0)loadMemories(1);
    }
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
    const multiMemoryContext=result.context_used?.multi_memory===true;
    const conversationContext=result.context_used?.conversation===true;

    answerSource.className='route-badge';
    if(route==='memory-capture'){
      answerSource.textContent='Memoria guardada';
      answerSource.classList.add('route-exact');
    }else if(route==='memory-exact'){
      answerSource.textContent='Memoria exacta';
      answerSource.classList.add('route-exact');
    }else if(route==='memory-semantic'){
      answerSource.textContent='Memoria semántica';
      answerSource.classList.add('route-semantic');
    }else if(route==='provider'&&(memoryContext||multiMemoryContext||conversationContext)){
      const parts=['IA'];
      if(multiMemoryContext)parts.push('RAG multi-memoria');
      else if(memoryContext)parts.push('memoria MCMA');
      if(conversationContext)parts.push('conversación');
      answerSource.textContent=parts.join(' + ');
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

  function switchMemoryView(mode){
    memoryState.mode=mode==='list'?'list':'tree';
    const treeMode=memoryState.mode==='tree';
    memoryTreeViewPanel.hidden=!treeMode;
    memoryListViewPanel.hidden=treeMode;
    memoryTreeView.classList.toggle('active',treeMode);
    memoryListView.classList.toggle('active',!treeMode);
    memoryTreeView.setAttribute('aria-pressed',treeMode?'true':'false');
    memoryListView.setAttribute('aria-pressed',treeMode?'false':'true');

    if(treeMode){
      memoryCount.textContent=number(memoryState.treeTotal)+' elementos · 0 tokens IA';
      if(memoryState.tree===null)loadMemoryTree();
    }else{
      if(memoryState.items.length===0)loadMemories(1);else renderMemoryList();
    }
  }

  function humanizeTreeSegment(value,file=false){
    let text=String(value||'');
    if(file)text=text.replace(/-[0-9a-f]{8,64}$/i,'');
    text=text.replace(/-/g,' ').trim();
    return text||'elemento';
  }

  function clearMemoryTreeDetail(){
    memoryState.selectedRef=null;
    memoryState.selectedKind=null;
    memoryState.selectedEditableText='';
    memoryTreeDetailContent.hidden=true;
    memoryTreeDetailEmpty.hidden=false;
    memoryTreeDetailEmpty.textContent='Selecciona un elemento de la biblioteca para descifrarlo.';
    canonicalMemoryActions.hidden=true;
    memoryUpdateInChatStatus.textContent='';
    libraryInlineEditActions.hidden=true;
    libraryInlineEditForm.hidden=true;
    libraryInlineEditStatus.textContent='';
    libraryInlineEditText.value='';
    interactionActions.hidden=true;
    interactionValidationStatus.textContent='';
    libraryCatalogWrap.hidden=true;
    for(const node of memoryTree.querySelectorAll('.memory-tree-file'))node.classList.remove('selected');
  }

  function libraryIcon(kind){
    return kind==='interaction'?'💬':kind==='knowledge'?'📖':kind==='memory'?'🧠':'📄';
  }

  function appendMemoryTreeNode(container,segment,node,depth){
    if(!node||typeof node!=='object')return;
    const keys=Object.keys(node).filter(key=>!key.startsWith('@'));
    const children=keys.sort((a,b)=>a.localeCompare(b,'es'));
    const logicalRef=typeof node['@ref']==='string'?node['@ref']:null;
    const kind=typeof node['@kind']==='string'?node['@kind']:'memory';

    if(children.length>0){
      const details=document.createElement('details');
      details.className='memory-tree-folder';
      details.open=depth<1;
      const summary=document.createElement('summary');
      summary.textContent='📁 '+humanizeTreeSegment(segment);
      details.appendChild(summary);
      const group=document.createElement('div');
      group.className='memory-tree-children';
      group.setAttribute('role','group');

      if(logicalRef){
        const file=document.createElement('button');
        file.type='button';
        file.className='memory-tree-file';
        file.dataset.memoryRef=logicalRef;
        file.textContent=libraryIcon(kind)+' '+humanizeTreeSegment(segment,true);
        file.addEventListener('click',()=>loadMemoryTreeDetail(logicalRef));
        group.appendChild(file);
      }

      for(const child of children)appendMemoryTreeNode(group,child,node[child],depth+1);
      details.appendChild(group);
      container.appendChild(details);
      return;
    }

    if(logicalRef){
      const file=document.createElement('button');
      file.type='button';
      file.className='memory-tree-file';
      file.dataset.memoryRef=logicalRef;
      file.textContent=libraryIcon(kind)+' '+humanizeTreeSegment(segment,true);
      file.addEventListener('click',()=>loadMemoryTreeDetail(logicalRef));
      container.appendChild(file);
    }
  }

  function countLibraryLeaves(node){
    if(!node||typeof node!=='object')return 0;
    let total=typeof node['@ref']==='string'?1:0;
    for(const [key,value] of Object.entries(node)){
      if(!key.startsWith('@'))total+=countLibraryLeaves(value);
    }
    return total;
  }

  function renderMemoryTree(){
    memoryTree.replaceChildren();
    const tree=memoryState.tree&&typeof memoryState.tree==='object'?memoryState.tree:{};
    const roots=Object.keys(tree).filter(key=>!key.startsWith('@'));

    if(roots.length===0){
      const empty=document.createElement('p');
      empty.className='memory-empty';
      empty.textContent='Todavía no hay elementos en tu biblioteca cognitiva.';
      memoryTree.appendChild(empty);
    }else{
      for(const segment of roots)appendMemoryTreeNode(memoryTree,segment,tree[segment],0);
    }

    memoryState.treeTotal=countLibraryLeaves(tree);
    memoryCount.textContent=number(memoryState.treeTotal)+' referencias · 0 tokens IA';
  }

  async function loadMemoryTree(){
    memoryCount.textContent='Descifrando catálogo de biblioteca… · 0 tokens IA';
    try{
      const data=await api('/mcma/v1/library-tree',{method:'GET',headers:{}});
      const result=data.library||{};
      memoryState.tree=result.tree&&typeof result.tree==='object'?result.tree:{};
      renderMemoryTree();
    }catch(error){
      memoryState.tree=null;
      memoryTree.replaceChildren();
      const failed=document.createElement('p');
      failed.className='memory-empty';
      failed.textContent='No se pudo abrir la biblioteca: '+error.message;
      memoryTree.appendChild(failed);
      memoryCount.textContent='Error al leer biblioteca';
    }
  }

  function treeDisplayContent(content){
    if(typeof content==='string')return content;
    if(content&&typeof content==='object'&&typeof content.content==='string')return content.content;
    if(content===null||content===undefined)return '—';
    return JSON.stringify(content,null,2);
  }

  function editableLibraryContent(object){
    if(object?.kind==='knowledge'){
      const value=object?.content?.answer?.value;
      return typeof value==='string'?value:(value===null||value===undefined?'':JSON.stringify(value,null,2));
    }
    if(object?.kind==='memory'){
      const content=object?.content;
      if(typeof content==='string')return content;
      if(content&&typeof content==='object'&&typeof content.content==='string')return content.content;
    }
    return '';
  }

  function libraryEditSummary(edit){
    const billing=edit?.billing||{};
    const usage=billing.usage||{};
    const tokens=Number(usage.total_tokens??usage.totalTokens??0);
    const semantic=edit?.semantic_index;
    const semanticText=semantic
      ?'embedding y semántica regenerados'
      :'sin proveedor de embedding configurado';
    return 'Guardado · verified · confianza 0.95 · warm · stable · '
      +semanticText+' · '+number(tokens)+' tokens IA';
  }

  async function saveLibraryObjectEdit(ref,content){
    return api('/mcma/v1/library-object/edit',{
      method:'POST',
      body:JSON.stringify({
        ref,
        content,
        request_id:'req_'+randomHex(16)
      })
    });
  }

  function catalogBadges(catalog){
    const badges=[];
    const groups=[
      ['Tema',catalog.topics],['Proyecto',catalog.projects],['Persona',catalog.people],
      ['Personaje',catalog.characters],['Entidad',catalog.entities],['Fuente',catalog.sources]
    ];
    for(const [prefix,values] of groups){
      if(!Array.isArray(values))continue;
      for(const value of values)badges.push(memoryBadge(prefix+': '+value));
    }
    return badges;
  }

  function renderMemoryTreeDetail(object){
    memoryState.selectedRef=object.logical_ref||null;
    memoryState.selectedKind=object.kind||null;
    memoryState.selectedEditableText=editableLibraryContent(object);
    memoryTreeDetailEmpty.hidden=true;
    memoryTreeDetailContent.hidden=false;
    canonicalMemoryActions.hidden=true;
    memoryUpdateInChatStatus.textContent='';
    libraryInlineEditActions.hidden=true;
    libraryInlineEditForm.hidden=true;
    libraryInlineEditStatus.textContent='';
    libraryInlineEditText.value='';
    interactionActions.hidden=true;
    interactionValidationStatus.textContent='';
    libraryCatalogWrap.hidden=true;

    for(const node of memoryTree.querySelectorAll('.memory-tree-file')){
      node.classList.toggle('selected',node.dataset.memoryRef===memoryState.selectedRef);
    }

    const metadata=object.metadata&&typeof object.metadata==='object'?object.metadata:{};
    memoryTreeDetailPath.textContent=object.logical_ref||'—';
    memoryTreeDetailObject.textContent=object.object_id||'—';
    memoryTreeDetailHash.textContent=object.storage_hash||'—';
    memoryTreeDetailRevision.textContent=String(metadata.revision||1);
    memoryTreeDetailUpdated.textContent=memoryDate(metadata.updated_at||metadata.created_at);

    if(object.kind==='interaction'){
      const interaction=object.interaction||{};
      const catalog=interaction.catalog&&typeof interaction.catalog==='object'?interaction.catalog:{};
      const validation=interaction.validation&&typeof interaction.validation==='object'?interaction.validation:{};
      memoryTreeDetailTitle.textContent=catalog.title||interaction.question||'Interacción';
      libraryAnswerLabel.textContent='Respuesta descifrada';
      memoryTreeDetailAnswer.textContent=treeDisplayContent(interaction.answer?.value);
      librarySourceLabel.textContent='Pregunta original';
      memoryTreeSourceWrap.hidden=false;
      memoryTreeDetailSource.textContent=interaction.question||'—';
      memoryTreeDetailLayer.textContent=metadata.cognitive_layer||'30-episodic';
      memoryTreeDetailScope.textContent=metadata.scope||'session';
      memoryTreeDetailTemperature.textContent=metadata.temperature||'hot';
      memoryTreeDetailMaturity.textContent=metadata.maturity||'observed';

      const badges=[
        memoryBadge('💬 Interacción'),
        memoryBadge('Estado: '+(validation.state||'unverified')),
        memoryBadge('Ruta: '+routeLabel(interaction.route)),
        memoryBadge('Sesión: '+String(interaction.conversation_id||'—').slice(-8))
      ];
      memoryTreeDetailBadges.replaceChildren(...badges);

      const cb=catalogBadges(catalog);
      libraryCatalogWrap.hidden=cb.length===0;
      libraryCatalogBadges.replaceChildren(...cb);

      interactionActions.hidden=false;
      interactionApprove.disabled=validation.state==='verified';
      interactionDiscard.disabled=validation.state==='retracted';
      return;
    }

    if(object.kind==='knowledge'){
      const record=object.content&&typeof object.content==='object'?object.content:{};
      const epistemic=record.epistemic&&typeof record.epistemic==='object'?record.epistemic:{};
      memoryTreeDetailTitle.textContent=record.intent?.question||'Knowledge';
      libraryAnswerLabel.textContent='Respuesta / conocimiento descifrado';
      memoryTreeDetailAnswer.textContent=treeDisplayContent(record.answer?.value);
      librarySourceLabel.textContent='Procedencia';
      memoryTreeSourceWrap.hidden=false;
      memoryTreeDetailSource.textContent=JSON.stringify(record.provenance||[],null,2);
      memoryTreeDetailLayer.textContent=metadata.cognitive_layer||'40-semantic';
      memoryTreeDetailScope.textContent=metadata.scope||'knowledge';
      memoryTreeDetailTemperature.textContent=metadata.temperature||'warm';
      memoryTreeDetailMaturity.textContent=metadata.maturity||'knowledge';
      memoryTreeDetailBadges.replaceChildren(
        memoryBadge('📖 Knowledge'),
        memoryBadge('Estado: '+(epistemic.validation_state||'unverified')),
        memoryBadge('Confianza: '+Number(epistemic.confidence||0).toFixed(2)),
        memoryBadge('Frescura: '+(record.freshness?.class||'stable')),
        memoryBadge((epistemic.validation_state==='verified'&&Number(epistemic.confidence||0)>=0.95)?'Reutilizable: Sí':'Reutilizable: requiere revisión')
      );
      libraryInlineEditActions.hidden=false;
      return;
    }

    const content=object.content;
    const canonical=content&&typeof content==='object'&&!Array.isArray(content)?content:{};
    const classification=canonical.classification&&typeof canonical.classification==='object'?canonical.classification:{};
    const lastSegment=String(object.logical_ref||'').split('/').pop()||'recuerdo';
    memoryTreeDetailTitle.textContent=typeof canonical.title==='string'&&canonical.title.trim()!==''?canonical.title:humanizeTreeSegment(lastSegment,true);
    libraryAnswerLabel.textContent='Recuerdo descifrado';
    memoryTreeDetailAnswer.textContent=treeDisplayContent(content);
    const source=canonical.source&&typeof canonical.source==='object'&&typeof canonical.source.original==='string'?canonical.source.original:'';
    librarySourceLabel.textContent='Texto original guardado';
    memoryTreeSourceWrap.hidden=source==='';
    memoryTreeDetailSource.textContent=source;
    memoryTreeDetailLayer.textContent=metadata.cognitive_layer||classification.cognitive_layer||'—';
    memoryTreeDetailScope.textContent=metadata.scope||classification.scope||'—';
    memoryTreeDetailTemperature.textContent=metadata.temperature||classification.temperature||'—';
    memoryTreeDetailMaturity.textContent=metadata.maturity||'—';
    const categories=Array.isArray(classification.category_path)?classification.category_path:[];
    const knowledgeState=object.knowledge_state&&typeof object.knowledge_state==='object'?object.knowledge_state:{};
    const badges=[memoryBadge('🧠 Memoria personal')];
    if(categories.length)badges.push(memoryBadge('📁 '+categories.join(' / ')));
    badges.push(memoryBadge(memoryTreeDetailTemperature.textContent),memoryBadge(memoryTreeDetailLayer.textContent));
    if(knowledgeState.validation_state){
      badges.push(
        memoryBadge('Estado: '+knowledgeState.validation_state),
        memoryBadge('Confianza: '+Number(knowledgeState.confidence||0).toFixed(2)),
        memoryBadge('Frescura: '+(knowledgeState.freshness_class||'stable')),
        memoryBadge(knowledgeState.reusable?'Reutilizable: Sí':'Reutilizable: requiere revisión')
      );
    }
    memoryTreeDetailBadges.replaceChildren(...badges);

    if(typeof object.logical_ref==='string'&&object.logical_ref.startsWith('memory://user/')){
      canonicalMemoryActions.hidden=false;
      memoryUpdateInChat.disabled=false;
      libraryInlineEditActions.hidden=false;
    }
  }

  function updateSelectedMemoryInChat(){
    const ref=memoryState.selectedRef;
    if(!ref||memoryState.selectedKind==='interaction'||memoryState.selectedKind==='knowledge'||!ref.startsWith('memory://user/'))return;

    const conversationId=currentConversationId();
    const title=memoryTreeDetailTitle.textContent.trim()||'Memoria personal';
    setMemoryEditTarget(ref,title,conversationId);
    memoryUpdateInChatStatus.textContent='Seleccionada para actualización exacta en Chat.';

    activateTab('ask');
    if(questionInput.value.trim()===''){
      questionInput.value='Actualiza esta memoria con: ';
      questionInput.style.height='auto';
      questionInput.style.height=Math.min(questionInput.scrollHeight,180)+'px';
    }
    composerStatus.textContent='Edición exacta activa · MCMA actualizará sólo la memoria seleccionada en Biblioteca.';
    questionInput.focus();
    questionInput.setSelectionRange(questionInput.value.length,questionInput.value.length);
  }

  function openTreeInlineEditor(){
    if(
      !memoryState.selectedRef
      ||!['memory','knowledge'].includes(memoryState.selectedKind)
    )return;
    libraryInlineEditText.value=memoryState.selectedEditableText||'';
    libraryInlineEditForm.hidden=false;
    libraryInlineEditStatus.textContent='';
    libraryInlineEditText.focus();
    libraryInlineEditText.setSelectionRange(
      libraryInlineEditText.value.length,
      libraryInlineEditText.value.length
    );
  }

  function closeTreeInlineEditor(){
    libraryInlineEditForm.hidden=true;
    libraryInlineEditText.value='';
  }

  async function saveTreeInlineEditor(event){
    event.preventDefault();
    const ref=memoryState.selectedRef;
    const content=libraryInlineEditText.value.trim();
    if(!ref||content==='')return;

    libraryInlineEditSave.disabled=true;
    libraryInlineEditCancel.disabled=true;
    libraryInlineEditStatus.textContent='Guardando corrección y regenerando embedding…';
    try{
      const data=await saveLibraryObjectEdit(ref,content);
      const summary=libraryEditSummary(data.edit||{});
      memoryState.items=[];
      memoryState.tree=null;
      closeTreeInlineEditor();
      await loadMemoryTree();
      await loadMemoryTreeDetail(ref);
      libraryInlineEditStatus.textContent=summary;
      await loadBilling();
    }catch(error){
      libraryInlineEditStatus.textContent='No se pudo guardar: '+error.message;
    }finally{
      libraryInlineEditSave.disabled=false;
      libraryInlineEditCancel.disabled=false;
    }
  }

  async function loadMemoryTreeDetail(logicalRef){
    memoryTreeDetailEmpty.textContent='Descifrando elemento…';
    try{
      const data=await api('/mcma/v1/library-object?ref='+encodeURIComponent(logicalRef),{method:'GET',headers:{}});
      renderMemoryTreeDetail(data.object||{});
    }catch(error){
      clearMemoryTreeDetail();
      memoryTreeDetailEmpty.textContent='No se pudo descifrar el elemento: '+error.message;
    }
  }

  async function validateInteraction(action){
    if(!memoryState.selectedRef||memoryState.selectedKind!=='interaction')return;
    interactionApprove.disabled=true;
    interactionDiscard.disabled=true;
    interactionValidationStatus.textContent=action==='approve'
      ?'Catalogando y aprobando conocimiento…'
      :'Descartando conocimiento…';
    try{
      const data=await api('/mcma/v1/interaction-validation',{
        method:'POST',
        body:JSON.stringify({ref:memoryState.selectedRef,action})
      });
      const validation=data.validation||{};
      const billing=validation.billing||{};
      const usage=billing.usage||{};
      const statusText=(action==='approve'?'Conocimiento aprobado':'Conocimiento descartado')+
        ' · '+number(usage.total_tokens||0)+' tokens · '+number(billing.credit_units_charged||0)+' créditos';
      const ref=memoryState.selectedRef;
      memoryState.tree=null;
      await loadMemoryTree();
      await loadMemoryTreeDetail(ref);
      interactionValidationStatus.textContent=statusText;
      await loadBilling();
    }catch(error){
      interactionValidationStatus.textContent=error.message;
      interactionApprove.disabled=false;
      interactionDiscard.disabled=false;
    }
  }

  function clearMemoryDetail(){
    memoryState.selectedId=null;
    memoryState.selectedListRef=null;
    memoryState.selectedListEditableText='';
    memoryDetailContent.hidden=true;
    memoryDetailEmpty.hidden=false;
    memoryValidationStatus.textContent='';
    memoryInlineEditForm.hidden=true;
    memoryInlineEditText.value='';
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
    memoryState.selectedListRef=memory.logical_ref||null;
    const selectedValue=memory.answer?.value;
    memoryState.selectedListEditableText=typeof selectedValue==='string'
      ?selectedValue
      :(selectedValue===null||selectedValue===undefined?'':JSON.stringify(selectedValue,null,2));
    memoryDetailEmpty.hidden=true;
    memoryDetailContent.hidden=false;
    memoryValidationStatus.textContent='';
    memoryInlineEditForm.hidden=true;
    memoryInlineEditText.value='';

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

  function openListInlineEditor(){
    if(!memoryState.selectedId||!memoryState.selectedListRef)return;
    memoryInlineEditText.value=memoryState.selectedListEditableText||'';
    memoryInlineEditForm.hidden=false;
    memoryValidationStatus.textContent='';
    memoryInlineEditText.focus();
    memoryInlineEditText.setSelectionRange(
      memoryInlineEditText.value.length,
      memoryInlineEditText.value.length
    );
  }

  function closeListInlineEditor(){
    memoryInlineEditForm.hidden=true;
    memoryInlineEditText.value='';
  }

  async function saveListInlineEditor(event){
    event.preventDefault();
    const ref=memoryState.selectedListRef;
    const id=memoryState.selectedId;
    const content=memoryInlineEditText.value.trim();
    if(!ref||!id||content==='')return;

    memoryInlineEditSave.disabled=true;
    memoryInlineEditCancel.disabled=true;
    memoryValidationStatus.textContent='Guardando corrección y regenerando embedding…';
    try{
      const data=await saveLibraryObjectEdit(ref,content);
      const summary=libraryEditSummary(data.edit||{});
      closeListInlineEditor();
      await loadMemories(memoryState.page);
      await loadMemoryDetail(id);
      memoryValidationStatus.textContent=summary;
      memoryState.tree=null;
      await loadBilling();
    }catch(error){
      memoryValidationStatus.textContent='No se pudo guardar: '+error.message;
    }finally{
      memoryInlineEditSave.disabled=false;
      memoryInlineEditCancel.disabled=false;
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
    const sections=[];
    const badges=[];

    if(used.memory===true){
      const value=used.answer?.value;
      const memoryText=typeof value==='string'?value:(value!==undefined?JSON.stringify(value,null,2):'Memoria usada, pero esta traza antigua no contiene el texto inyectado.');
      sections.push('MEMORIA MCMA VALIDADA\n'+memoryText);
      badges.push(
        memoryBadge('Memoria MCMA','route-badge route-ai-memory'),
        memoryBadge(used.validation_state||'—'),
        memoryBadge('Confianza '+Number(used.confidence||0).toFixed(2)),
        memoryBadge(used.freshness_class||'—')
      );
    }

    const multiMemory=used.multi_memory_context;
    const memories=Array.isArray(multiMemory?.memories)?multiMemory.memories:[];
    if(used.multi_memory===true&&memories.length>0){
      const rendered=memories.map((memory,index)=>{
        const provenance=Array.isArray(memory.provenance)
          ?memory.provenance.map(source=>(source.source_type||'fuente')+': '+(source.reference||'')).join(' · ')
          :'';
        const answer=typeof memory.answer==='string'?memory.answer:JSON.stringify(memory.answer??'');
        return 'MEMORIA '+(index+1)+' · RAG '+Number(memory.rag_score||0).toFixed(3)+' · similitud '+Number(memory.similarity||0).toFixed(3)+' · confianza '+Number(memory.confidence||0).toFixed(2)+'\n'
          +'Estado: '+(memory.validation_state||'—')+' · frescura '+(memory.freshness_class||'—')+(memory.stale?' · stale':'')+'\n'
          +'Procedencia: '+(provenance||'—')+'\n'
          +'Pregunta: '+(memory.question||'')+'\n'
          +'Respuesta: '+answer;
      }).join('\n\n');
      sections.push('RAG MULTI-MEMORIA SELECCIONADO\n'+rendered);
      const selection=multiMemory.selection||{};
      badges.push(
        memoryBadge(number(memories.length)+' memorias RAG','route-badge route-ai-memory'),
        memoryBadge(number(selection.estimated_tokens_upper_bound||0)+' tokens máx. RAG'),
        memoryBadge(selection.strategy||'multi-memory-rag')
      );
    }

    const conversation=used.conversation_context;
    const turns=Array.isArray(conversation?.turns)?conversation.turns:[];
    if(used.conversation===true&&turns.length>0){
      const rendered=turns.map((turn,index)=>{
        const q=typeof turn.question==='string'?turn.question:'';
        const a=typeof turn.answer==='string'?turn.answer:JSON.stringify(turn.answer??'');
        return 'TURNO '+(index+1)+' · '+(turn.at||'')+' · relevancia '+Number(turn.relevance_score||0).toFixed(2)+'\nUsuario: '+q+'\nAsistente: '+a;
      }).join('\n\n');
      sections.push('HISTORIAL CONVERSACIONAL SELECCIONADO\n'+rendered);
      const selection=conversation.selection||{};
      badges.push(
        memoryBadge(number(turns.length)+' turnos'),
        memoryBadge(number(selection.estimated_tokens_upper_bound||0)+' tokens máx. contexto'),
        memoryBadge(selection.strategy||'selección contextual')
      );
    }

    const broadRecall=used.broad_recall_context;
    const recalled=Array.isArray(broadRecall?.items)?broadRecall.items:[];
    if(used.broad_recall===true&&recalled.length>0){
      const rendered=recalled.map((item,index)=>{
        return 'MEMORIA '+(index+1)+' · '+(item.kind||'memoria')+' · '+(item.validation_state||'unverified')+' · confianza '+Number(item.confidence||0).toFixed(2)+'\n'
          +'Ref: '+(item.logical_ref||'')+'\n'
          +'Pregunta: '+(item.question||'')+'\n'
          +'Contenido: '+(item.answer||'');
      }).join('\n\n');
      sections.push('RECUPERACIÓN AMPLIA DE MEMORIA · '+(broadRecall.subject||'')+'\n'+rendered);
      badges.push(
        memoryBadge(number(recalled.length)+' memorias relacionadas','route-badge route-ai-memory'),
        memoryBadge('Entidad: '+(broadRecall.subject||'—')),
        memoryBadge(broadRecall.selection?.strategy||'broad-entity-recall')
      );
    }

    if(sections.length>0){
      contextInjectedAnswer.textContent=sections.join('\n\n---\n\n');
      contextInjectedMeta.replaceChildren(...badges);
    }else{
      if(trace.route==='memory-exact'||trace.route==='memory-semantic'){
        contextInjectedAnswer.textContent='No se inyectó contexto en un modelo: MCMA respondió directamente desde '+routeLabel(trace.route).toLowerCase()+'.';
      }else{
        contextInjectedAnswer.textContent='No se seleccionó memoria ni historial conversacional para esta generación.';
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
    const providerUsage=Array.isArray(billing.provider_usage)?billing.provider_usage:[];
    for(const component of providerUsage){
      const componentTokens=Number(component.input_tokens||0)+Number(component.output_tokens||0)+Number(component.embedding_tokens||0);
      contextInjectedMeta.append(
        memoryBadge((component.kind||'modelo')+' · '+number(componentTokens)+' tokens · '+(component.provider_id||'proveedor'))
      );
    }

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
          memoryDate(trace.at)+' · '+routeLabel(trace.route)+((trace.context_used?.memory===true||trace.context_used?.multi_memory===true||trace.context_used?.conversation===true)?' · contexto MCMA':''),
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
      login.hidden=true;logout.hidden=false;registerBox.hidden=true;account.hidden=false;mainTabs.hidden=false;accountDrawer.hidden=false;
      $('library').textContent=data.user.library_id;
      form.querySelectorAll('textarea,input,button').forEach(el=>el.disabled=false);
      activateTab('ask');
      await Promise.all([loadBilling(),loadKeys(),loadStripe(),detectAdmin(),loadConversations()]);
      await recoverStoredPendingRequest();
    }catch(error){
      account.hidden=true;apiKeysBox.hidden=true;stripeBox.hidden=true;accountDrawer.hidden=true;mainTabs.hidden=true;memoryExplorer.hidden=true;contextPanel.hidden=true;adminLink.hidden=true;clearIdentity();
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

  async function applyChatResult(pending,result,question){
    const answerValue=result.answer?.value ?? JSON.stringify(result,null,2);
    pending.article.classList.remove('pending','error');
    pending.content.textContent=displayChatValue(answerValue);
    setChatMessageMeta(pending,resultMessageMeta(result));
    answer.textContent=displayChatValue(answerValue);
    renderAnswerMeta(result);
    await loadBilling();

    if(result.interaction_archive?.recorded===true){
      const cid=result.interaction_archive.conversation_id;
      if(/^conv_[0-9a-f]{32}$/.test(cid))setConversationId(cid);
      memoryState.tree=null;
      conversationTitle.textContent=shortConversationTitle(question);
      composerStatus.textContent=result.interaction_archive.recovered===true
        ?'Respuesta recuperada del archivo cifrado después de una interrupción.'
        :'Respuesta archivada en la conversación actual.';
      await loadConversations({openCurrent:false});
    }else{
      composerStatus.textContent='Respuesta recibida, pero el archivo persistente no confirmó esta interacción.';
    }

    if(result.stored===true)memoryState.items=[];
    if(!memoryExplorer.hidden&&memoryState.mode==='tree')await loadMemoryTree();
    else if(!memoryExplorer.hidden&&memoryState.mode==='list'&&result.stored===true)await loadMemories(1);
  }

  form.addEventListener('submit',async event=>{
    event.preventDefault();
    const question=questionInput.value.trim();
    if(question==='')return;

    const conversationId=currentConversationId();
    const editTarget=getMemoryEditTarget();
    const mutationRef=editTarget&&editTarget.conversation_id===conversationId?editTarget.ref:null;
    const requestId='req_'+randomHex(16);
    setPendingRequest({request_id:requestId,conversation_id:conversationId,question,at:new Date().toISOString()});

    if(chatMessages.querySelector('.chat-empty-state'))clearChatMessages();
    appendChatMessage('user',question);
    const pending=appendChatMessage('assistant','MCMA está pensando…');
    pending.article.classList.add('pending');

    send.disabled=true;
    answer.textContent='Procesando…';
    answerMeta.hidden=true;
    composerStatus.textContent='MCMA está respondiendo…';
    questionInput.value='';
    questionInput.style.height='';

    let completed=false;
    let permanentFailure=false;
    try{
      let result=null;
      try{
        const data=await api('/mcma/v1/ask',{method:'POST',body:JSON.stringify({
          question,current:$('current').checked,remember:$('remember').checked,
          conversation_id:conversationId,request_id:requestId,
          response_language:(navigator.languages&&navigator.languages[0])||navigator.language||'',
          ...(mutationRef?{mutation_ref:mutationRef}:{})
        })});
        result=data.result||{};
      }catch(error){
        const transient=(!error.status)||(error.status>=502&&error.status<=504&&!error.code);
        if(!transient)throw error;
        composerStatus.textContent='La conexión se interrumpió; MCMA está comprobando si la respuesta terminó…';
        pending.content.textContent='La respuesta sigue procesándose…';
        result=await recoverRequest(requestId,conversationId,45);
        if(!result)throw new Error('La respuesta aún no aparece en el archivo. No se volvió a cobrar ni a generar; MCMA conservará el request_id para recuperarla.');
      }

      await applyChatResult(pending,result,question);
      if(mutationRef&&result.route==='memory-mutation'&&result.stored===true){
        renderMemoryEditTarget(conversationId);
        composerStatus.textContent='Memoria actualizada · la selección exacta sigue activa para esta conversación.';
      }
      completed=true;
      clearPendingRequest(requestId);
    }catch(error){
      const keepPending=String(error.message||'').includes('conservará el request_id');
      permanentFailure=!keepPending;
      pending.article.classList.remove('pending');
      pending.article.classList.add('error');
      pending.content.textContent=error.message;
      answer.textContent=error.message;
      answerMeta.hidden=true;
      composerStatus.textContent=keepPending
        ?'La petición queda pendiente y podrá recuperarse sin volver a generar.'
        :'Error al responder: '+error.message;
      if(permanentFailure)clearPendingRequest(requestId);
    }finally{
      send.disabled=false;
      questionInput.focus();
      chatMessages.scrollTop=chatMessages.scrollHeight;
    }
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

  mainTabs.addEventListener('click',event=>{
    const button=event.target.closest('[data-tab-target]');
    if(!button||!mainTabs.contains(button))return;
    activateTab(button.dataset.tabTarget);
  });

  mainTabs.addEventListener('keydown',event=>{
    if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;
    const current=tabButtons.findIndex(button=>button.getAttribute('aria-selected')==='true');
    if(current<0)return;

    event.preventDefault();
    let next=current;
    if(event.key==='ArrowLeft')next=(current-1+tabButtons.length)%tabButtons.length;
    if(event.key==='ArrowRight')next=(current+1)%tabButtons.length;
    if(event.key==='Home')next=0;
    if(event.key==='End')next=tabButtons.length-1;

    const button=tabButtons[next];
    activateTab(button.dataset.tabTarget);
    button.focus();
  });

  memoryTreeView.addEventListener('click',()=>switchMemoryView('tree'));
  memoryListView.addEventListener('click',()=>switchMemoryView('list'));
  memoryUpdateInChat.addEventListener('click',updateSelectedMemoryInChat);
  memoryEditTargetClear.addEventListener('click',()=>{
    clearMemoryEditTarget();
    composerStatus.textContent='Selección exacta quitada · MCMA volverá a resolver la memoria por contexto cuando sea necesario.';
    questionInput.focus();
  });
  interactionApprove.addEventListener('click',()=>validateInteraction('approve'));
  interactionDiscard.addEventListener('click',()=>validateInteraction('discard'));
  newConversation.addEventListener('click',startNewConversation);
  conversationSearch.addEventListener('input',()=>{
    conversationState.filter=conversationSearch.value;
    renderConversationList();
  });
  conversationSidebarToggle.addEventListener('click',()=>{
    const open=!chatWorkspace.classList.contains('sidebar-open');
    chatWorkspace.classList.toggle('sidebar-open',open);
    conversationSidebarToggle.setAttribute('aria-expanded',open?'true':'false');
  });
  questionInput.addEventListener('input',()=>{
    questionInput.style.height='auto';
    questionInput.style.height=Math.min(questionInput.scrollHeight,180)+'px';
  });
  questionInput.addEventListener('keydown',event=>{
    if(event.key==='Enter'&&!event.shiftKey&&!event.isComposing){
      event.preventDefault();
      if(!send.disabled)form.requestSubmit();
    }
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

  window.addEventListener('beforeunload',stopChatSpeech);

  logout.addEventListener('click',async()=>{
    logout.disabled=true;logout.textContent='Saliendo…';
    setSessionState('pending','Cerrando sesión…');
    try{
      await fetch('logout',{method:'POST',credentials:'same-origin'});
      account.hidden=true;apiKeysBox.hidden=true;stripeBox.hidden=true;accountDrawer.hidden=true;mainTabs.hidden=true;memoryExplorer.hidden=true;contextPanel.hidden=true;adminLink.hidden=true;clearIdentity();
      setSessionState('inactive','Sesión cerrada');
      login.hidden=false;logout.hidden=true;
      form.querySelectorAll('textarea,input,button').forEach(el=>el.disabled=true);
      setTimeout(()=>location.replace('./?signed_out=1'),120);
    }catch(error){
      setSessionState('inactive','No se pudo cerrar la sesión');
      logout.disabled=false;logout.textContent='Salir';
    }
  });

  prepareAccountDrawer();
  const initialConversationId=currentConversationId();
  renderMemoryEditTarget(initialConversationId);
  loadMe();
})();

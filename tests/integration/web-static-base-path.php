<?php
declare(strict_types=1);

function check(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}
}

$root=dirname(__DIR__,2).'/apps/web/public';
$index=file_get_contents($root.'/index.html');
$app=file_get_contents($root.'/app.js');
$admin=file_get_contents($root.'/admin.html');

check(is_string($index),'index.html readable');
check(str_contains($index,'href="favicon.svg?v=20260901-5"'),'favicon is base-path relative and cache-busted');
check(str_contains($index,'href="app.css?v=20260903-2"'),'index stylesheet is base-path relative and cache-busted');
check(str_contains($index,'href="admin.html"'),'admin link is base-path relative');
check(str_contains($index,'href="login"'),'login link is base-path relative');
check(str_contains($index,'src="app.js?v=20260903-3"'),'app script is base-path relative and cache-busted');
check(str_contains($index,'id="memoryExplorer"'),'memory explorer markup is present');
check(str_contains($index,'id="memoryConfirm"'),'memory confirm action is present');
check(str_contains($index,'id="memoryDiscard"'),'memory discard action is present');
check(str_contains($index,'id="memoryTreeView"'),'memory tree view toggle is present');
check(str_contains($index,'id="memoryListView"'),'memory list view toggle is present');
check(str_contains($index,'id="memoryTree"'),'memory tree container is present');
check(str_contains($index,'id="memoryTreeDetailContent"'),'decrypted tree detail panel is present');
check(str_contains($index,'id="memoryUpdateInChat"'),'Biblioteca exact-memory update action is present');
check(str_contains($index,'id="memoryEditTarget"'),'Chat exact-memory target banner is present');
check(str_contains($index,'id="memoryEditTargetClear"'),'Chat exact-memory target clear action is present');
check(str_contains($index,'id="conversationLabel"'),'conversation session label is present');
check(str_contains($index,'id="conversationSidebar"'),'conversation sidebar is present');
check(str_contains($index,'id="conversationSearch"'),'conversation search is present');
check(str_contains($index,'id="conversationList"'),'conversation list is present');
check(str_contains($index,'id="chatMessages"'),'chat message stream is present');
check(str_contains($index,'id="conversationTitle"'),'conversation title is present');
check(str_contains($index,'id="composerStatus"'),'chat composer status is present');
check(str_contains($index,'MCMA puede combinar memorias relevantes y conversación'),'multi-memory RAG guidance is present');
check(str_contains($index,'id="newConversation"'),'new conversation control is present');
check(str_contains($index,'id="interactionApprove"'),'interaction approval action is present');
check(str_contains($index,'id="interactionDiscard"'),'interaction discard action is present');
check(str_contains($index,'data-tab-target="ask"'),'Chat tab is present');
check(str_contains($index,'aria-selected="true">Chat</button>'),'Chat tab label is present');
check(str_contains($index,'data-tab-target="memory"'),'Memory tab is present');
check(str_contains($index,'data-tab-target="context"'),'Context tab is present');
check(str_contains($index,'id="contextPanel"'),'Context transparency panel is present');
check(str_contains($index,'Contexto MCMA'),'Context tab label is present');

check(is_string($app),'app.js readable');
check(str_contains($app,"fetch('logout'"),'logout endpoint is base-path relative');
check(str_contains($app,"location.replace('./?signed_out=1')"),'logout refresh is base-path relative');
check(str_contains($app,"'/mcma/v1/memories?'"),'memory explorer list endpoint is wired');
check(str_contains($app,"api('/mcma/v1/library-tree'"),'cognitive library tree endpoint is wired');
check(str_contains($app,"api('/mcma/v1/library-object?ref='"),'cognitive library object endpoint is wired');
check(str_contains($app,"api('/mcma/v1/interaction-validation'"),'interaction validation endpoint is wired');
check(str_contains($app,'conversation_id:conversationId'),'conversation id is sent with ask requests');
check(str_contains($app,'mutation_ref:mutationRef'),'exact Biblioteca mutation ref is sent with ask requests');
check(str_contains($app,'mcma_memory_edit_target_v1'),'exact Biblioteca edit target survives within the conversation session');
check(str_contains($app,'updateSelectedMemoryInChat'),'Biblioteca update action is wired to Chat');
check(str_contains($app,"api('/mcma/v1/conversations'"),'conversation archive list endpoint is wired');
check(str_contains($app,"api('/mcma/v1/conversations/'+encodeURIComponent(conversationId)"),'conversation archive detail endpoint is wired');
check(str_contains($app,"appendChatMessage('user'"),'user messages render in chat stream');
check(str_contains($app,"appendChatMessage('assistant'"),'assistant messages render in chat stream');
check(str_contains($app,"textContent=displayChatValue"),'chat output uses textContent');
check(str_contains($app,"navigator.clipboard.writeText"),'assistant copy action is wired');
check(str_contains($app,"SpeechSynthesisUtterance"),'assistant read-aloud action is wired');
check(str_contains($app,"speechSynthesis"),'browser speech synthesis integration is present');
check(str_contains($app,"loadConversations({openCurrent:false})"),'conversation sidebar refreshes after archive');
check(str_contains($app,"request_id:requestId"),'chat sends stable client request id');
check(str_contains($app,"navigator.languages&&navigator.languages[0]"),'chat sends browser response language');
check(str_contains($app,"/mcma/v1/requests/"),'chat request recovery endpoint is wired');
check(str_contains($app,"mcma_pending_request_v1"),'pending request survives reload in session storage');
check(str_contains($app,"recoverRequest("),'timed-out response recovery polling is wired');
check(str_contains($app,"broad_recall_context"),'broad memory recall transparency is rendered');
check(str_contains($app,"provider_usage"),'per-provider usage detail is rendered');
check(str_contains($app,"switchMemoryView('tree')"),'memory tree view switch is wired');
check(str_contains($app,"switchMemoryView('list')"),'memory list view switch is wired');
check(str_contains($app,"'/validation'"),'memory explorer validation endpoint is wired');
check(str_contains($app,"api('/mcma/v1/context'"),'context transparency endpoint is wired');
check(str_contains($app,'HISTORIAL CONVERSACIONAL SELECCIONADO'),'context transparency renders selected conversation turns');
check(str_contains($app,'conversation_context'),'conversation context metadata is rendered');
check(str_contains($app,'RAG MULTI-MEMORIA SELECCIONADO'),'context transparency renders selected multi-memory RAG');
check(str_contains($app,'multi_memory_context'),'multi-memory RAG metadata is rendered');
check(str_contains($app,"activateTab('ask')"),'Chat tab activation is wired');
check(str_contains($app,"mainTabs.addEventListener('click'"),'tab click delegation is wired');
check(str_contains($app,"closest('[data-tab-target]')"),'tab click target resolution is wired');
check(str_contains($app,"mainTabs.addEventListener('keydown'"),'keyboard tab navigation is wired');

check(is_string($admin),'admin.html readable');
check(str_contains($admin,'href="favicon.svg"'),'admin favicon is base-path relative');
check(str_contains($admin,'href="app.css"'),'admin stylesheet is base-path relative');
check(str_contains($admin,'href="./"'),'admin back link is base-path relative');
check(str_contains($admin,'src="admin.js"'),'admin script is base-path relative');

echo "web static base-path integration ok\n";

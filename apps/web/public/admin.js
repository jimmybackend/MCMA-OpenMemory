(() => {
  const status=document.getElementById('adminStatus'),tbody=document.querySelector('#users tbody');
  async function api(path,options={}){
    const r=await fetch(path,{credentials:'same-origin',...options,headers:{'Content-Type':'application/json',...(options.headers||{})}});
    const d=await r.json().catch(()=>({}));if(!r.ok)throw new Error(d.message||'HTTP '+r.status);return d;
  }
  const n=v=>new Intl.NumberFormat().format(Number(v||0));
  async function post(path,data){return api(path,{method:'POST',body:JSON.stringify(data)});}
  function button(label,fn){const b=document.createElement('button');b.textContent=label;b.onclick=fn;return b;}

  async function load(){
    try{
      const data=await api('/mcma/v1/admin/users',{method:'GET',headers:{}});
      status.textContent=data.users.length+' usuarios';tbody.innerHTML='';
      for(const u of data.users){
        const tr=document.createElement('tr');
        const values=[u.user_id,u.status,u.billing.account.service_status,u.billing.account.plan_id,n(u.billing.available_units),n(u.totals.total_tokens),n(u.totals.payments)];
        for(const value of values){const td=document.createElement('td');td.textContent=value;tr.appendChild(td);}
        const actions=document.createElement('td');actions.className='actions-cell';
        actions.append(
          button('Créditos',async()=>{const units=Number(prompt('Ajuste de créditos (+/-)','1000'));if(!Number.isInteger(units)||!units)return;await post('/mcma/v1/admin/users/'+u.user_id+'/credits',{units,reason:'panel superadmin'});await load();}),
          button('Plan',async()=>{const plan_id=prompt('Plan',u.billing.account.plan_id);if(!plan_id)return;await post('/mcma/v1/admin/users/'+u.user_id+'/plan',{plan_id});await load();}),
          button(u.billing.account.service_status==='active'?'Suspender':'Activar',async()=>{const status=u.billing.account.service_status==='active'?'suspended':'active';await post('/mcma/v1/admin/users/'+u.user_id+'/service',{status});await load();}),
          button(u.status==='active'?'Deshabilitar':'Habilitar',async()=>{const status=u.status==='active'?'disabled':'active';await post('/mcma/v1/admin/users/'+u.user_id+'/access',{status});await load();}),
          button('Pago',async()=>{const provider=prompt('Proveedor: stripe, paypal, mercadopago, bank-transfer o manual','manual');if(!provider)return;const provider_payment_id=prompt('Referencia de pago');if(!provider_payment_id)return;const credit_units=Number(prompt('Créditos','1000'));const amount_micros=Number(prompt('Monto en micros de moneda','0'));const currency=prompt('Moneda','USD')||'USD';await post('/mcma/v1/admin/users/'+u.user_id+'/payments',{provider,provider_payment_id,credit_units,amount_micros,currency});await load();})
        );
        tr.appendChild(actions);tbody.appendChild(tr);
      }
    }catch(e){status.textContent=e.message;tbody.innerHTML='';}
  }

  document.getElementById('refresh').onclick=load;
  document.getElementById('setPricing').onclick=async()=>{
    const provider_id=prompt('Provider ID exacto');if(!provider_id)return;
    const version=prompt('Versión de tarifa','v1')||'v1';
    const currency=prompt('Moneda','USD')||'USD';
    const fields=['input_cost_micros_per_1m','output_cost_micros_per_1m','cached_cost_micros_per_1m','embedding_cost_micros_per_1m','input_credit_units_per_1m','output_credit_units_per_1m','cached_credit_units_per_1m','embedding_credit_units_per_1m'];
    const body={provider_id,version,currency};for(const f of fields)body[f]=Number(prompt(f,'0')||'0');
    try{await post('/mcma/v1/admin/pricing',body);status.textContent='Precio actualizado';}catch(e){status.textContent=e.message;}
  };
  load();
})();

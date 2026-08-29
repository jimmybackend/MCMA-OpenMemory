(() => {
  const status = document.getElementById('status');
  const login = document.getElementById('login');
  const logout = document.getElementById('logout');
  const account = document.getElementById('account');
  const library = document.getElementById('library');
  const registerBox = document.getElementById('registerBox');
  const register = document.getElementById('register');
  const form = document.getElementById('askForm');
  const send = document.getElementById('send');
  const answer = document.getElementById('answer');

  async function api(path, options = {}) {
    const response = await fetch(path, {
      credentials: 'same-origin',
      ...options,
      headers: {'Content-Type': 'application/json', ...(options.headers || {})}
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      const error = new Error(data.message || 'Error HTTP ' + response.status);
      error.status = response.status;
      error.code = data.error;
      throw error;
    }
    return data;
  }

  async function loadMe() {
    try {
      const data = await api('/mcma/v1/me', {method: 'GET', headers: {}});
      status.textContent = 'Sesión activa';
      login.hidden = true;
      logout.hidden = false;
      registerBox.hidden = true;
      account.hidden = false;
      library.textContent = data.user.library_id;
      form.querySelectorAll('textarea,input,button').forEach(el => el.disabled = false);
    } catch (error) {
      account.hidden = true;
      if (error.status === 401) {
        status.textContent = 'Sin sesión';
        login.hidden = false;
        logout.hidden = true;
        registerBox.hidden = true;
      } else if (error.code === 'user_not_registered') {
        status.textContent = 'Usuario autenticado, memoria no registrada';
        login.hidden = true;
        logout.hidden = false;
        registerBox.hidden = false;
      } else {
        status.textContent = error.message;
      }
      form.querySelectorAll('textarea,input,button').forEach(el => el.disabled = true);
    }
  }

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    send.disabled = true;
    answer.textContent = 'Procesando…';
    try {
      const data = await api('/mcma/v1/ask', {
        method: 'POST',
        body: JSON.stringify({
          question: document.getElementById('question').value,
          current: document.getElementById('current').checked,
          remember: document.getElementById('remember').checked
        })
      });
      const result = data.result || {};
      answer.textContent = result.answer ?? JSON.stringify(result, null, 2);
    } catch (error) {
      answer.textContent = error.message;
    } finally {
      send.disabled = false;
    }
  });

  register.addEventListener('click', async () => {
    register.disabled = true;
    try {
      await api('/mcma/v1/register', {method: 'POST', body: '{}'});
      await loadMe();
    } catch (error) {
      status.textContent = error.message;
    } finally {
      register.disabled = false;
    }
  });

  logout.addEventListener('click', async () => {
    await fetch('/logout', {method: 'POST', credentials: 'same-origin'});
    location.href = '/';
  });

  loadMe();
})();

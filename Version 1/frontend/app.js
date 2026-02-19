// app.js
const api = './api.php';

async function apiFetch(action, data = {}) {
  const form = new FormData();
  form.append('action', action);
  for (const k in data) form.append(k, data[k]);
  const resp = await fetch(api, { method: 'POST', body: form });
  return resp.json();
}

async function listMissions() {
  const res = await fetch(api + '?action=list_missions');
  const data = await res.json();
  const list = document.getElementById('missionsList');
  list.innerHTML = '';
  if (!data.ok) { list.innerText = 'Error cargando.'; return; }
  if (data.missions.length === 0) list.innerText = 'No hay misiones.';
  data.missions.forEach(m => {
    const el = document.createElement('div');
    el.className = 'mission-item';
    el.innerHTML = `<div><strong>${escapeHtml(m.title)}</strong><div style="font-size:12px;color:var(--muted)">${m.created_at} · ${m.status}</div></div>`;
    const btn = document.createElement('button');
    btn.innerText = 'Ver / Abrir';
    btn.onclick = () => openMission(m.id);
    el.appendChild(btn);
    list.appendChild(el);
  });
}

function escapeHtml(s){ return String(s).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;'); }

document.getElementById('createBtn').addEventListener('click', async () => {
  const title = document.getElementById('title').value.trim();
  const desc = document.getElementById('description').value.trim();
  if (!title || !desc) return alert('Título y descripción necesarios');
  const r = await apiFetch('create_mission', { title, description: desc });
  if (!r.ok) return alert('Error: ' + r.error);
  alert('Misión creada con id ' + r.mission_id);
  document.getElementById('title').value = '';
  document.getElementById('description').value = '';
  listMissions();
  openMission(r.mission_id);
});

let currentMissionId = null;

async function openMission(id) {
  currentMissionId = id;
  const res = await fetch(api + '?action=get_mission&id=' + id);
  const data = await res.json();
  if (!data.ok) return alert('Error: ' + data.error);
  const m = data.mission;
  document.getElementById('missionDetail').style.display = 'block';
  document.getElementById('mTitle').innerText = m.title;
  document.getElementById('mDesc').innerText = m.description;
  document.getElementById('mStatus').innerText = m.status;
  document.getElementById('mStep').innerText = m.current_step;
  renderSteps(data.steps);
  loadLogs(id);
}

function renderSteps(steps) {
  const cont = document.getElementById('stepsContainer');
  cont.innerHTML = '';
  if (!steps || steps.length === 0) {
    cont.innerText = 'Sin pasos aún.';
    return;
  }
  steps.forEach(s => {
    const div = document.createElement('div');
    div.className = 'step';
    const files = s.generated_files ? JSON.parse(s.generated_files) : [];
    let filesHtml = '';
    if (files.length) {
      filesHtml = '<details><summary>Archivos generados (' + files.length + ')</summary>';
      files.forEach(f => {
        filesHtml += `<h4>${escapeHtml(f.path)}</h4><pre><code>${escapeHtml(f.content)}</code></pre>`;
      });
      filesHtml += '</details>';
    }
    div.innerHTML = `<strong>Paso ${s.step_index} · ${s.status}</strong>
      <div style="margin-top:6px">${escapeHtml(s.description)}</div>
      ${filesHtml}
      <div style="font-size:12px;color:var(--muted);margin-top:8px">Evaluación: ${escapeHtml(s.evaluation || '')}</div>`;
    cont.appendChild(div);
    // apply highlight (if code blocks exist)
    hljs.highlightAll();
  });
}

document.getElementById('runStepBtn').addEventListener('click', async () => {
  if (!currentMissionId) return alert('Abre una misión primero.');
  document.getElementById('runStepBtn').disabled = true;
  const form = new FormData();
  form.append('action', 'run_step');
  form.append('id', currentMissionId);
  const resp = await fetch(api, { method: 'POST', body: form });
  const data = await resp.json();
  if (!data.ok) {
    alert('Error: ' + data.error);
    document.getElementById('runStepBtn').disabled = false;
    return;
  }
  // update UI
  await openMission(currentMissionId);
  if (data.mission_completed) {
    alert('Misión completada 🎉');
  }
  document.getElementById('runStepBtn').disabled = false;
});

document.getElementById('downloadBtn').addEventListener('click', () => {
  if (!currentMissionId) return alert('Abre una misión');
  window.location = api + '?action=download_project&id=' + currentMissionId;
});

document.getElementById('refreshBtn').addEventListener('click', async () => {
  if (!currentMissionId) return;
  await openMission(currentMissionId);
});

async function loadLogs(id) {
  const res = await fetch(api + '?action=logs&id=' + id);
  const data = await res.json();
  const el = document.getElementById('logs');
  if (!data.ok) { el.innerText = 'Error cargando logs'; return; }
  el.innerText = data.logs.map(l => `[${l.created_at}] ${l.level.toUpperCase()}: ${l.message}`).join('\n');
}

// initial
listMissions();


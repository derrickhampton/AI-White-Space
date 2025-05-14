const MAX_TRIES = 5;
const STORAGE_KEY = 'ws_tool_tries';
const KEY_DATE = 'ws_tool_date';

// Utility to get YYYY-MM-DD for today
function todayString() {
  const d = new Date();
  return d.toISOString().slice(0, 10);
}

let storedDate = localStorage.getItem(KEY_DATE);
let tries = parseInt(localStorage.getItem(STORAGE_KEY) || '0', 10);

if (storedDate !== todayString()) {
  tries = 0;
  localStorage.setItem(MAX_TRIES, '0');
  localStorage.setItem(KEY_DATE, todayString());
}

const mapping = {
  '\u00A0': 'NBSP',
  '\u200B': 'ZWSP',
  '\u200C': 'ZWNJ',
  '\u200D': 'ZWJ',
  '\u2060': 'WORD_JOINER',
  '\u200A': 'HAIR_SPACE',
  '\u2009': 'THIN_SPACE',
  '\u2007': 'FIGURE_SPACE',
  '\u2002': 'EN_SPACE',
  '\u2003': 'EM_SPACE',
  '\u202F': 'NNB_SPACE'
};

const inputEl = document.getElementById('input');
const outputEl = document.getElementById('output');
const modal = document.getElementById('modal');
const foundList = document.getElementById('foundList');
const messageEl = document.getElementById('message');
const closeBtn = modal.querySelector('.close');

function updateMessage() {
  /*if (tries >= MAX_TRIES) {
    messageEl.textContent = `Maximum of ${MAX_TRIES} uses reached.`;
    inputEl.disabled = true;
  } else {
    messageEl.textContent = `Uses: ${tries} / ${MAX_TRIES}`;
    inputEl.disabled = false;
  }*/
}

function highlightHidden(text) {
  let result = '';
  const found = new Set();

  for (let ch of text) {
    if (mapping[ch]) {
      found.add(mapping[ch]);
      result += `<span class="highlight">[${mapping[ch]}]</span>`;
    } else {
      result += ch
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
    }
  }

  if (found.size) showModal(Array.from(found));
  else closeModal();

  return result;
}

function showModal(items) {
  foundList.innerHTML = '';
  items.forEach(name => {
    let li = document.createElement('li');
    li.textContent = name;
    foundList.appendChild(li);
  });
  modal.classList.add('show');
}

function closeModal() {
  modal.classList.remove('show');
}

closeBtn.addEventListener('click', closeModal);
modal.addEventListener('click', e => {
  if (e.target === modal) closeModal();
});

inputEl.addEventListener('input', () => {
  //if (tries >= MAX_TRIES) return;
  //tries++;
  localStorage.setItem(STORAGE_KEY, tries);
  localStorage.setItem(KEY_DATE, todayString());
  updateMessage();
  outputEl.innerHTML = highlightHidden(inputEl.value);
});

// Initialize
updateMessage();
outputEl.innerHTML = highlightHidden(inputEl.value);

document.getElementById('downloadBtn').addEventListener('click', () => {
    // 1. Grab the text content (you can also use .innerHTML if you prefer)
    const text = document.getElementById('output').innerText;

    // 2. Create a Blob from the text
    const blob = new Blob([text], { type: 'text/plain' });

    // 3. Create a temporary download link
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'output.txt';      // default filename
    document.body.appendChild(a);   // Firefox requires link in body
    a.click();                      // trigger the download
    document.body.removeChild(a);   // cleanup
    URL.revokeObjectURL(url);       // free up memory
  });
const dropzone = document.getElementById('dropzone');
const dropzoneText = document.getElementById('dropzone-text');
const fileInput = document.getElementById('file-input');
const urlInput = document.getElementById('url-input');
const uploadBtn = document.getElementById('upload-btn');
const cancelBtn = document.getElementById('cancel-btn');
const fileQueueContainer = document.getElementById('file-queue');
const resultsContainer = document.getElementById('results-container');
const resultsTitle = document.getElementById('results-title');
const resultsDiv = document.getElementById('results');
const notification = document.getElementById('notification');
const notificationMessage = document.getElementById('notification-message');
let notificationTimeout;
let filesToUpload = [];

const showNotification = (message, type = 'success', duration = 5000) => {
    clearTimeout(notificationTimeout);
    notificationMessage.textContent = message;
    notification.className = 'fixed top-4 right-4 p-3 sm:p-4 text-base sm:text-lg max-w-xs sm:max-w-sm z-50';
    notification.classList.add(type, 'show');
    notificationTimeout = setTimeout(() => notification.classList.remove('show'), duration);
};

const resetUploader = () => {
    filesToUpload = [];
    fileInput.value = '';
    urlInput.value = '';
    updateFileQueueUI();
    dropzoneText.textContent = 'Seret file ke sini, atau klik untuk memilih';
    resultsContainer.classList.add('hidden');
    uploadBtn.disabled = false;
    uploadBtn.textContent = 'Unggah';
};

const resetQueueAndButton = () => {
    filesToUpload = [];
    fileInput.value = '';
    urlInput.value = '';
    updateFileQueueUI();
    uploadBtn.disabled = false;
    uploadBtn.textContent = 'Unggah';
}

const updateFileQueueUI = () => {
    fileQueueContainer.innerHTML = '';
    if (filesToUpload.length === 0 && !urlInput.value) return;
    if (urlInput.value) {
         const urlElement = document.createElement('div');
         urlElement.className = 'p-2 bg-black/20 border-2 border-[var(--dropzone-border)]';
         urlElement.textContent = `URL: ${urlInput.value.substring(0, 30)}${urlInput.value.length > 30 ? '...' : ''}`;
         fileQueueContainer.appendChild(urlElement);
    }
    filesToUpload.forEach(file => {
        const fileElement = document.createElement('div');
        fileElement.className = 'p-2 bg-black/20 border-2 border-[var(--dropzone-border)]';
        fileElement.textContent = `${file.name} (${(file.size / 1024 / 1024).toFixed(2)} MB)`;
        fileQueueContainer.appendChild(fileElement);
    });
};

const displaySuccess = (files) => {
    resultsContainer.classList.remove('hidden');
    resultsTitle.textContent = 'Hasil URL:';
    resultsTitle.classList.remove('text-red-400');
    resultsDiv.innerHTML = '';
    files.forEach(file => {
        const url = `${window.location.origin}${file.url}`;
        const extension = file.url.split('.').pop().toUpperCase() || 'FILE';
        const resultEl = document.createElement('div');
        resultEl.className = 'flex flex-col sm:flex-row items-stretch sm:items-center gap-2 md:gap-4';
        resultEl.innerHTML = `
            <input type="text" readonly value="${url}" class="url-to-copy w-full url-result p-2 text-base sm:text-lg focus:outline-none border-4 border-[var(--bg-panel)] text-center sm:text-left">
            <span class="pixel-button btn-copy text-base sm:text-lg text-center pointer-events-none">${extension}</span>
            <button class="copy-btn pixel-button btn-copy px-4 py-2 text-lg sm:text-xl w-full sm:w-auto">Salin</button>
        `;
        resultsDiv.appendChild(resultEl);
    });
    document.querySelectorAll('.copy-btn').forEach(button => {
        button.addEventListener('click', e => {
            const urlField = e.currentTarget.parentElement.querySelector('.url-to-copy');
            urlField.select();
            try {
                document.execCommand('copy');
                showNotification('URL disalin!', 'success');
            } catch (err) {
                showNotification('Gagal menyalin!', 'error');
            }
        });
    });
};

dropzone.addEventListener('click', () => fileInput.click());
cancelBtn.addEventListener('click', resetUploader);
['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => dropzone.addEventListener(eventName, e => { e.preventDefault(); e.stopPropagation(); }));
['dragenter', 'dragover'].forEach(eventName => dropzone.addEventListener(eventName, () => dropzone.classList.add('bg-[var(--dropzone-hover-bg)]')));
['dragleave', 'drop'].forEach(eventName => dropzone.addEventListener(eventName, () => dropzone.classList.remove('bg-[var(--dropzone-hover-bg)]')));
dropzone.addEventListener('drop', e => handleFiles(e.dataTransfer.files));
fileInput.addEventListener('change', e => handleFiles(e.target.files));
urlInput.addEventListener('input', updateFileQueueUI);

const handleFiles = (newFiles) => {
    if (filesToUpload.length + newFiles.length > 3) {
        showNotification('Maksimal 3 file sekali unggah.', 'error');
        return;
    }
    filesToUpload.push(...Array.from(newFiles));
    dropzoneText.textContent = `${filesToUpload.length} file dipilih.`;
    updateFileQueueUI();
};

uploadBtn.addEventListener('click', async () => {
    if (filesToUpload.length === 0 && !urlInput.value) {
        showNotification('Pilih file atau masukkan URL dulu.', 'error');
        return;
    }
    if (filesToUpload.length > 0 && urlInput.value) {
        showNotification('Unggah file atau URL, jangan keduanya.', 'error');
        return;
    }
    resultsContainer.classList.add('hidden');
    const formData = new FormData();
    if (filesToUpload.length > 0) {
        filesToUpload.forEach(file => formData.append('files[]', file));
    } else if (urlInput.value) {
        formData.append('url', urlInput.value);
    }
    uploadBtn.disabled = true;
    uploadBtn.textContent = 'Mengunggah...';
    fileQueueContainer.innerHTML = `<div class="progress-bar-container"><div id="total-progress" class="progress-bar" style="width: 50%; background-color: var(--btn-go-bg);"></div></div>`;
    const progressBar = document.getElementById('total-progress');
    try {
        const response = await fetch(config.apiEndpoint, { method: 'POST', body: formData });
        const result = await response.json();
        progressBar.style.width = '100%';
        if (!response.ok || !result.success) {
            throw new Error(result.error || `HTTP error! Status: ${response.status}`);
        }
        showNotification('Upload sukses!', 'success');
        if (result.files && result.files.length > 0) {
            displaySuccess(result.files);
        }
        if (result.errors && result.errors.length > 0) {
            showNotification(`Beberapa file gagal: ${result.errors.join(', ')}`, 'error', 8000);
        }
    } catch (error) {
        progressBar.style.backgroundColor = 'var(--btn-stop-bg)';
        showNotification(error.message, 'error', 8000);
    } finally {
        resetQueueAndButton();
    }
});

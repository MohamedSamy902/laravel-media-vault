/**
 * Advanced File Upload JS Client
 *
 * Provides resumable chunked file transfers, concurrent multi-file upload support,
 * localStorage state persistence per file, and auto-detection of interrupted uploads on page load.
 */

document.addEventListener('DOMContentLoaded', function () {

    const STORAGE_PREFIX = 'afu_upload_';
    const TTL_MS = 24 * 60 * 60 * 1000; // 24 hours TTL

    /**
     * Generates a unique fingerprint key for a file based on name, size, and last modified timestamp.
     */
    function getFileFingerprint(file) {
        return STORAGE_PREFIX + `${file.name}_${file.size}_${file.lastModified}`;
    }

    /**
     * Storage Helper for managing upload session persistence in localStorage.
     */
    const AfuStorage = {
        save: function (fingerprint, data) {
            try {
                const payload = {
                    sessionId: data.sessionId,
                    fileName: data.fileName,
                    fileSize: data.fileSize,
                    totalChunks: data.totalChunks,
                    uploadedChunkIndexes: data.uploadedChunkIndexes || [],
                    updatedAt: Date.now()
                };
                localStorage.setItem(fingerprint, JSON.stringify(payload));
            } catch (e) {
                console.warn('LaravelMediaVault: localStorage write failed', e);
            }
        },

        get: function (fingerprint) {
            try {
                const item = localStorage.getItem(fingerprint);
                if (!item) return null;
                const data = JSON.parse(item);
                if (Date.now() - (data.updatedAt || 0) > TTL_MS) {
                    localStorage.removeItem(fingerprint);
                    return null;
                }
                return data;
            } catch (e) {
                return null;
            }
        },

        remove: function (fingerprint) {
            try {
                localStorage.removeItem(fingerprint);
            } catch (e) {}
        },

        getAllPending: function () {
            const list = [];
            for (let i = 0; i < localStorage.length; i++) {
                const key = localStorage.key(i);
                if (key && key.startsWith(STORAGE_PREFIX)) {
                    const data = this.get(key);
                    if (data) {
                        list.push({ fingerprint: key, ...data });
                    }
                }
            }
            return list;
        }
    };

    /**
     * Single File Uploader Class
     */
    class SingleMediaVaulter {
        constructor(file, options = {}) {
            this.file = file;
            this.options = options;
            this.chunkSize = options.chunkSize || 5 * 1024 * 1024; // 5MB default
            this.totalChunks = Math.ceil(file.size / this.chunkSize);
            this.fingerprint = getFileFingerprint(file);
            this.sessionId = null;
            this.uploadedChunks = new Set();
            this.isAborted = false;
        }

        async start(resumeSessionId = null, serverReceivedChunks = []) {
            if (resumeSessionId) {
                this.sessionId = resumeSessionId;
            }

            const storedData = AfuStorage.get(this.fingerprint);
            if (storedData) {
                if (!this.sessionId && storedData.sessionId) {
                    this.sessionId = storedData.sessionId;
                }
                if (Array.isArray(storedData.uploadedChunkIndexes)) {
                    storedData.uploadedChunkIndexes.forEach(idx => this.uploadedChunks.add(idx));
                }
            }

            if (Array.isArray(serverReceivedChunks)) {
                serverReceivedChunks.forEach(idx => this.uploadedChunks.add(idx));
            }

            // Begin uploading missing chunks sequentially or concurrently
            for (let chunkIndex = 0; chunkIndex < this.totalChunks; chunkIndex++) {
                if (this.isAborted) return;

                if (this.uploadedChunks.has(chunkIndex)) {
                    this.updateProgress();
                    continue;
                }

                try {
                    await this.sendChunk(chunkIndex);
                    this.uploadedChunks.add(chunkIndex);

                    // Update localStorage after each successful chunk
                    AfuStorage.save(this.fingerprint, {
                        sessionId: this.sessionId,
                        fileName: this.file.name,
                        fileSize: this.file.size,
                        totalChunks: this.totalChunks,
                        uploadedChunkIndexes: Array.from(this.uploadedChunks)
                    });

                    this.updateProgress();
                } catch (err) {
                    if (this.options.onError) {
                        this.options.onError(err, this.file);
                    }
                    throw err;
                }
            }

            // Upload complete: remove from localStorage
            AfuStorage.remove(this.fingerprint);

            if (this.options.onSuccess) {
                this.options.onSuccess(this.file, this.sessionId);
            }
        }

        sendChunk(chunkIndex) {
            return new Promise((resolve, reject) => {
                const start = chunkIndex * this.chunkSize;
                const end = Math.min(start + this.chunkSize, this.file.size);
                const chunk = this.file.slice(start, end);

                const formData = new FormData();
                formData.append('file', chunk, this.file.name);
                formData.append('chunkNumber', chunkIndex + 1);
                formData.append('totalChunks', this.totalChunks);
                formData.append('originalName', this.file.name);
                formData.append('totalSize', this.file.size);
                if (this.sessionId) {
                    formData.append('sessionId', this.sessionId);
                }

                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                const xhr = new XMLHttpRequest();
                const uploadUrl = this.options.uploadUrl || '/media-vault/upload';

                xhr.open('POST', uploadUrl, true);
                if (csrfToken) {
                    xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken);
                }

                xhr.onload = () => {
                    if (xhr.status >= 200 && xhr.status < 300) {
                        try {
                            const resp = JSON.parse(xhr.responseText);
                            const sId = resp.sessionId || resp.result?.sessionId;
                            if (sId && !this.sessionId) {
                                this.sessionId = sId;
                            }
                            resolve(resp);
                        } catch (e) {
                            reject(new Error('Invalid JSON server response'));
                        }
                    } else if (xhr.status === 404 || xhr.status === 410) {
                        // Session expired on server
                        AfuStorage.remove(this.fingerprint);
                        reject(new Error('Upload session expired on server. Please start fresh.'));
                    } else {
                        reject(new Error(`Chunk upload failed with status ${xhr.status}`));
                    }
                };

                xhr.onerror = () => reject(new Error('Network connection error during chunk upload'));
                xhr.send(formData);
            });
        }

        updateProgress() {
            const percent = Math.floor((this.uploadedChunks.size / this.totalChunks) * 100);
            if (this.options.onProgress) {
                this.options.onProgress(percent, this.uploadedChunks.size, this.totalChunks, this.file);
            }
        }

        abort() {
            this.isAborted = true;
        }
    }

    /**
     * Auto-detect and prompt user to resume interrupted uploads on page load.
     */
    async function checkPendingUploads(options = {}) {
        const pending = AfuStorage.getAllPending();
        if (pending.length === 0) return;

        const prefix = options.routePrefix || '/media-vault';
        const container = document.getElementById(options.containerId || 'afu-resume-container') || createDefaultResumeContainer();

        for (const item of pending) {
            try {
                // Query server for ground truth session status
                const res = await fetch(`${prefix}/sessions/${item.sessionId}/status`, {
                    headers: { 'Accept': 'application/json' }
                });

                if (!res.ok) {
                    // Server session is dead/expired (404/410), clear local entry
                    AfuStorage.remove(item.fingerprint);
                    continue;
                }

                const serverData = await res.json();
                if (!serverData.status || serverData.is_expired || serverData.session_status === 'complete' || serverData.status === 'complete') {
                    AfuStorage.remove(item.fingerprint);
                    continue;
                }

                const receivedCount = (serverData.received_chunks || []).length;
                const percent = Math.floor((receivedCount / serverData.total_chunks) * 100);

                renderResumeCard(container, item, serverData, percent, options);

            } catch (e) {
                console.warn('LaravelMediaVault: Error checking pending session status', e);
            }
        }
    }

    function renderResumeCard(container, localItem, serverData, percent, options) {
        const card = document.createElement('div');
        card.className = 'afu-resume-card';
        card.style.cssText = 'border:1px solid #e0e0e0; padding:12px; margin-bottom:10px; border-radius:6px; background:#fff; font-family:sans-serif;';
        
        card.innerHTML = `
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <strong>📄 Interrupted Upload Detected:</strong> ${localItem.fileName} 
                    <span style="color:#666; font-size:0.9em;">(${percent}% completed)</span>
                </div>
                <div>
                    <button class="afu-btn-resume" style="background:#28a745; color:#fff; border:none; padding:6px 12px; border-radius:4px; cursor:pointer; margin-right:6px;">Resume</button>
                    <button class="afu-btn-discard" style="background:#dc3545; color:#fff; border:none; padding:6px 12px; border-radius:4px; cursor:pointer;">Start Fresh</button>
                </div>
            </div>
        `;

        const resumeBtn = card.querySelector('.afu-btn-resume');
        const discardBtn = card.querySelector('.afu-btn-discard');

        resumeBtn.addEventListener('click', () => {
            alert(`To resume uploading "${localItem.fileName}", please re-select the file using the file picker.`);
            const input = document.getElementById(options.inputId || 'afu-fileInput');
            if (input) {
                input.dataset.resumeSessionId = localItem.sessionId;
                input.dataset.resumeFingerprint = localItem.fingerprint;
                input.dataset.serverReceivedChunks = JSON.stringify(serverData.received_chunks || []);
                input.click();
            }
            card.remove();
        });

        discardBtn.addEventListener('click', () => {
            AfuStorage.remove(localItem.fingerprint);
            card.remove();
        });

        container.appendChild(card);
    }

    function createDefaultResumeContainer() {
        let div = document.getElementById('afu-resume-container');
        if (!div) {
            div = document.createElement('div');
            div.id = 'afu-resume-container';
            div.style.cssText = 'max-width:800px; margin:15px auto; padding:0 10px;';
            document.body.insertBefore(div, document.body.firstChild);
        }
        return div;
    }

    /**
     * Main Global Upload Function supporting single or concurrent multi-file uploads.
     */
    window.afuUploadFiles = function (fileOrFiles, options = {}) {
        const files = Array.isArray(fileOrFiles) ? fileOrFiles : (fileOrFiles instanceof FileList ? Array.from(fileOrFiles) : [fileOrFiles]);

        const uploaders = files.map(file => {
            const uploader = new SingleMediaVaulter(file, options);
            
            // Check if resume parameters attached
            const resumeSessionId = options.resumeSessionId || null;
            const serverReceivedChunks = options.serverReceivedChunks || [];

            uploader.start(resumeSessionId, serverReceivedChunks).catch(err => {
                console.error(`LaravelMediaVault: Error uploading ${file.name}:`, err);
            });

            return uploader;
        });

        return uploaders;
    };

    window.afuUploadFile = function (options = {}) {
        const fileInput = document.getElementById(options.inputId || 'afu-fileInput');
        if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
            alert('Please select at least one file first.');
            return;
        }

        const files = Array.from(fileInput.files);
        const resumeSessionId = fileInput.dataset.resumeSessionId || null;
        let serverReceivedChunks = [];
        if (fileInput.dataset.serverReceivedChunks) {
            try {
                serverReceivedChunks = JSON.parse(fileInput.dataset.serverReceivedChunks);
            } catch (e) {}
        }

        return window.afuUploadFiles(files, {
            ...options,
            resumeSessionId: resumeSessionId,
            serverReceivedChunks: serverReceivedChunks,
        });
    };

    window.afuCheckPendingUploads = checkPendingUploads;

    // Run auto-check on DOM ready
    checkPendingUploads();
});
<template>
    <NcModal :show="show" :title="t('batch_renamer', 'Batch rename')" @close="close">
        <div class="rename-modal-content">
            
            <!-- SCHRITT 1: Eingabe & Umbenennen -->
            <div v-if="!isDone">
                <p>{{ t('batch_renamer', 'Selected files:') }} <strong>{{ files.length }}</strong></p>
                
                <NcTextField 
                    :label="t('batch_renamer', 'Naming pattern (use # for digits)')" 
                    :value.sync="pattern" 
                    :placeholder="t('batch_renamer', 'e.g. Image_###')" 
                    :disabled="loading"
                />
                <small class="hint">
                    {{ t('batch_renamer', 'Tip: ### = 001, ## = 01, #### = 0001') }}
                </small>
                
                <div class="row">
                    <NcTextField 
                        :label="t('batch_renamer', 'Start number')" 
                        type="number" 
                        :value.sync="startNumber" 
                        :disabled="loading"
                    />
                </div>

                <!-- Vorschau-Bereich vor dem Start -->
                <div class="preview-box">
                    <h4>{{ t('batch_renamer', 'Preview:') }}</h4>
                    <p v-if="pattern.trim() && !hasHash" class="warning-text">
                        ⚠️ {{ t('batch_renamer', 'The pattern must contain at least one # to number files.') }}
                    </p>
                    <ul v-if="hasHash">
                        <li v-for="(example, idx) in previewList" :key="idx">
                            <span class="old-name">{{ example.old }}</span> &rarr; <strong>{{ example.new }}</strong>
                        </li>
                    </ul>
                </div>

                <!-- Fortschrittsbalken während der Ausführung -->
                <div v-if="loading" class="progress-section">
                    <div class="progress-labels">
                        <span>{{ t('batch_renamer', 'Renaming files...') }}</span>
                        <span>{{ processedFiles }} / {{ totalFiles }} ({{ progressPercent }}%)</span>
                    </div>
                    
                    <div class="progress-bar-container">
                        <div class="progress-bar-fill" :style="{ width: progressPercent + '%' }"></div>
                    </div>
                    
                    <div v-if="currentFileName" class="progress-current-file">
                        {{ t('batch_renamer', 'Current file:') }} {{ currentFileName }}
                    </div>
                </div>

                <!-- Aktionen während Eingabe/Lauf -->
                <div class="modal-actions">
                    <NcButton :disabled="loading" @click="close">
                        {{ t('batch_renamer', 'Cancel') }}
                    </NcButton>
                    <NcButton 
                        type="primary" 
                        :disabled="loading || !pattern.trim() || !hasHash" 
                        @click="submit"
                    >
                        {{ loading ? t('batch_renamer', 'Renaming...') : t('batch_renamer', 'Rename now') }}
                    </NcButton>
                </div>
            </div>

            <!-- SCHRITT 2: Erfolgs-Übersicht & Protokoll -->
            <div v-else class="done-section">
                <div class="success-banner">
                    <span class="success-icon">✓</span>
                    <strong>{{ t('batch_renamer', '{count} files renamed successfully!', { count: historyLog.length }) }}</strong>
                </div>

                <div class="log-container">
                    <h4>{{ t('batch_renamer', 'Change log:') }}</h4>
                    <div class="log-scroll">
                        <div v-for="(item, idx) in historyLog" :key="idx" class="log-row">
                            <span class="log-old" :title="item.oldName">{{ item.oldName }}</span>
                            <span class="log-arrow">&rarr;</span>
                            <strong class="log-new" :title="item.newName">{{ item.newName }}</strong>
                        </div>
                    </div>
                </div>

                <div class="modal-actions">
                    <NcButton :disabled="isUndoing" @click="undo">
                        {{ isUndoing ? t('batch_renamer', 'Reverting...') : t('batch_renamer', '↩ Undo') }}
                    </NcButton>
                    <NcButton type="primary" :disabled="isUndoing" @click="finish">
                        {{ t('batch_renamer', 'Done') }}
                    </NcButton>
                </div>
            </div>

        </div>
    </NcModal>
</template>

<script>
import { NcModal, NcButton, NcTextField } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'

export default {
    name: 'RenameModal',
    components: { NcModal, NcButton, NcTextField },
    props: {
        show: { type: Boolean, default: false },
        files: { type: Array, required: true }
    },
    data() {
        return {
            pattern: '',
            startNumber: 1,
            loading: false,
            processedFiles: 0,
            totalFiles: 0,
            currentFileName: '',
            historyLog: [],
            isDone: false,
            isUndoing: false
        }
    },
    computed: {
        hasHash() {
            return this.pattern.includes('#')
        },
        progressPercent() {
            if (!this.totalFiles) return 0
            return Math.round((this.processedFiles / this.totalFiles) * 100)
        },
        previewList() {
            const raw = this.pattern.trim()
            if (!raw || !this.hasHash) return []

            const match = raw.match(/#+/)
            const hashPlaceholder = match ? match[0] : '#'
            const digits = hashPlaceholder.length

            return this.files.slice(0, 3).map((f, i) => {
                const ext = f.name.includes('.') ? f.name.split('.').pop() : ''
                const num = String(Number(this.startNumber) + i).padStart(digits, '0')
                const base = raw.replace(hashPlaceholder, num)
                return {
                    old: f.name,
                    new: ext ? `${base}.${ext}` : base
                }
            })
        }
    },
    methods: {
        t,
        close() {
            if (this.loading || this.isUndoing) return
            if (this.isDone) {
                this.finish()
            } else {
                this.$emit('close')
            }
        },
        finish() {
            this.$emit('close')
            window.location.reload()
        },
        computeNewName(originalName, index) {
            const raw = this.pattern.trim()
            const match = raw.match(/#+/)
            const hashPlaceholder = match ? match[0] : '#'
            const digits = hashPlaceholder.length
            const ext = originalName.includes('.') ? originalName.split('.').pop() : ''
            const num = String(Number(this.startNumber) + index).padStart(digits, '0')
            const base = raw.replace(hashPlaceholder, num)
            return ext ? `${base}.${ext}` : base
        },
        async submit() {
            if (!this.pattern.trim() || !this.hasHash || this.loading) return

            this.loading = true
            this.processedFiles = 0
            this.totalFiles = this.files.length
            this.historyLog = []

            try {
                const url = generateUrl('/apps/batch_renamer/api/v1/rename')

                for (let i = 0; i < this.files.length; i++) {
                    const file = this.files[i]
                    this.currentFileName = file.name
                    const calculatedNewName = this.computeNewName(file.name, i)

                    await axios.post(url, {
                        files: [file],
                        pattern: this.pattern.trim(),
                        startNumber: Number(this.startNumber) + i
                    })

                    this.processedFiles++
                    this.historyLog.push({
                        id: file.id,
                        oldName: file.name,
                        newName: calculatedNewName,
                        path: file.path
                    })
                }

                // Wechsel in die Protokoll-Ansicht
                this.isDone = true
            } catch (err) {
                alert(t('batch_renamer', 'Error renaming files: ') + (err.response?.data?.error || err.message))
            } finally {
                this.loading = false
                this.currentFileName = ''
            }
        },
        async undo() {
            if (this.isUndoing || this.historyLog.length === 0) return

            this.isUndoing = true
            try {
                const url = generateUrl('/apps/batch_renamer/api/v1/undo')
                await axios.post(url, {
                    items: this.historyLog
                })
                this.finish()
            } catch (err) {
                alert(t('batch_renamer', 'Error reverting changes: ') + (err.response?.data?.error || err.message))
                this.isUndoing = false
            }
        }
    }
}
</script>

<style scoped>
.rename-modal-content { 
    padding: 20px; 
}
.hint { 
    display: block; 
    color: var(--color-text-maxcontrast, #888); 
    margin-top: -5px; 
    margin-bottom: 15px; 
    font-size: 0.85em; 
}
.warning-text {
    color: var(--color-error, #e9322d);
    font-size: 0.9em;
    margin: 6px 0 10px 0;
}
.row { 
    display: flex; 
    gap: 15px; 
    margin-bottom: 15px; 
}
.preview-box { 
    background: rgba(128, 128, 128, 0.1); 
    padding: 12px; 
    border-radius: var(--border-radius-element, 6px); 
}
.preview-box ul { 
    list-style: none; 
    padding-left: 0; 
    margin: 8px 0 0 0; 
}
.old-name { 
    color: var(--color-text-maxcontrast, #888); 
    text-decoration: line-through; 
}
.progress-section {
    margin: 16px 0 10px 0;
    padding: 12px;
    background-color: var(--color-background-hover, rgba(128, 128, 128, 0.1));
    border-radius: var(--border-radius-element, 6px);
}
.progress-labels {
    display: flex;
    justify-content: space-between;
    font-size: 13px;
    font-weight: 500;
    margin-bottom: 8px;
}
.progress-bar-container {
    width: 100%;
    height: 8px;
    background-color: var(--color-border, #444);
    border-radius: 4px;
    overflow: hidden;
}
.progress-bar-fill {
    height: 100%;
    background-color: var(--color-primary-element, #0082c9);
    transition: width 0.15s ease-out;
}
.progress-current-file {
    margin-top: 6px;
    font-size: 11px;
    color: var(--color-text-maxcontrast, #888);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Protokoll-Styles nach Fertigstellung */
.success-banner {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--color-success, #46ba61);
    font-size: 1.1em;
    margin-bottom: 16px;
}
.success-icon {
    font-weight: bold;
    font-size: 1.2em;
}
.log-container h4 {
    margin-bottom: 8px;
}
.log-scroll {
    max-height: 200px;
    overflow-y: auto;
    background: rgba(128, 128, 128, 0.08);
    border: 1px solid var(--color-border, rgba(255, 255, 255, 0.1));
    border-radius: var(--border-radius-element, 6px);
    padding: 8px 12px;
}
.log-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 4px 0;
    font-size: 12px;
    border-bottom: 1px solid var(--color-border, rgba(255, 255, 255, 0.05));
}
.log-row:last-child {
    border-bottom: none;
}
.log-old {
    color: var(--color-text-maxcontrast, #888);
    text-decoration: line-through;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 45%;
}
.log-arrow {
    color: var(--color-text-maxcontrast, #888);
}
.log-new {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 45%;
}

.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 20px;
    padding-top: 15px;
    border-top: 1px solid var(--color-border, rgba(255, 255, 255, 0.1));
}
</style>
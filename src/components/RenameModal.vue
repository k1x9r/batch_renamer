<template>
    <NcModal :show="show" title="Stapel-Umbenennung" @close="close">
        <div class="rename-modal-content">
            <p>Ausgewählte Dateien: <strong>{{ files.length }}</strong></p>
            
            <NcTextField 
                label="Namensmuster (nutze # für die Ziffern)" 
                :value.sync="pattern" 
                placeholder="z. B. Bild_###" 
            />
            <small class="hint">Tipp: <code>###</code> = 001, <code>##</code> = 01, <code>####</code> = 0001</small>
            
            <div class="row">
                <NcTextField label="Startnummer" type="number" :value.sync="startNumber" />
            </div>

            <div class="preview-box">
                <h4>Vorschau:</h4>
                <p v-if="pattern.trim() && !hasHash" class="warning-text">
                    ⚠️ Das Muster muss mindestens ein <code>#</code> enthalten, damit die Dateien durchnummeriert werden können.
                </p>
                <ul v-if="hasHash">
                    <li v-for="(example, idx) in previewList" :key="idx">
                        <span class="old-name">{{ example.old }}</span> &rarr; <strong>{{ example.new }}</strong>
                    </li>
                </ul>
            </div>

            <div class="modal-actions">
                <NcButton @click="close">
                    Abbrechen
                </NcButton>
                <NcButton 
                    type="primary" 
                    :disabled="loading || !pattern.trim() || !hasHash" 
                    @click="submit"
                >
                    {{ loading ? 'Wird umbenannt...' : 'Jetzt umbenennen' }}
                </NcButton>
            </div>
        </div>
    </NcModal>
</template>

<script>
import { NcModal, NcButton, NcTextField } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

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
            loading: false
        }
    },
    computed: {
        hasHash() {
            return this.pattern.includes('#')
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
        close() {
            this.$emit('close')
        },
        async submit() {
            if (!this.pattern.trim() || !this.hasHash) return

            this.loading = true
            try {
                const url = generateUrl('/apps/batch_renamer/api/v1/rename')
                await axios.post(url, {
                    files: this.files,
                    pattern: this.pattern.trim(),
                    startNumber: Number(this.startNumber)
                })
                this.close()
                window.location.reload()
            } catch (err) {
                alert('Fehler beim Umbenennen: ' + (err.response?.data?.error || err.message))
            } finally {
                this.loading = false
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
.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 20px;
    padding-top: 15px;
    border-top: 1px solid var(--color-border, rgba(255, 255, 255, 0.1));
}
</style>
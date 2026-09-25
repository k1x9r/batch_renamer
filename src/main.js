import Vue from 'vue'
import RenameModal from './components/RenameModal.vue'
import { translate as t } from '@nextcloud/l10n'

window.appName = 'batch_renamer'
window.appVersion = '1.0.0'

const modalMount = document.createElement('div')
modalMount.id = 'batch-renamer-modal-mount'
document.body.appendChild(modalMount)

let modalInstance = null

function parseSelectedNode(item, currentDir) {
    if (!item) return null

    // Fall 1: Nextcloud liefert WebDAV-URL als String
    if (typeof item === 'string') {
        const cleanUrl = item.split('?')[0].split('#')[0]
        const decoded = decodeURIComponent(cleanUrl)

        const parts = decoded.split('/').filter(p => p.length > 0)
        let name = parts[parts.length - 1] || ''
        name = name.replace(/\s+\./g, '.').trim()

        let relPath = ''
        const davMatch = decoded.match(/\/remote\.php\/dav\/files\/[^/]+(\/.*)$/)
        if (davMatch && davMatch[1]) {
            relPath = davMatch[1]
        } else {
            relPath = (currentDir.endsWith('/') ? currentDir : currentDir + '/') + name
        }

        return {
            id: name,
            name: name,
            path: relPath
        }
    }

    // Fall 2: Nextcloud liefert Node-Objekt
    if (typeof item === 'object') {
        const id = item.fileid || item.id || item.fileId || item.attributes?.fileid || item.attributes?.id
        let name = item.name || item.basename || item.attributes?.name || item.attributes?.basename || ''
        name = String(name).replace(/\s+\./g, '.').trim()

        let fullPath = item.path || ''
        if (name) {
            if (fullPath && fullPath.endsWith(name)) {
                // Pfad ist bereits vollständig
            } else if (fullPath) {
                fullPath = (fullPath.endsWith('/') ? fullPath : fullPath + '/') + name
            } else {
                fullPath = (currentDir.endsWith('/') ? currentDir : currentDir + '/') + name
            }
        }

        return {
            id: id ? String(id) : String(name),
            name: String(name || id),
            path: fullPath
        }
    }

    return null
}

function getSelectedFiles() {
    try {
        const btn = document.getElementById('batch-renamer-btn') || Array.from(document.querySelectorAll('button')).find(b => 
            (b.textContent || '').includes('Verschieben') || (b.textContent || '').includes('Move')
        )

        let el = btn
        while (el && !el.__vue__) {
            el = el.parentElement
        }

        let comp = el?.__vue__
        while (comp) {
            const rawNodes = comp.selectedNodes || comp.$props?.selectedNodes
            if (rawNodes) {
                let list = []
                if (Array.isArray(rawNodes)) {
                    list = rawNodes
                } else if (rawNodes instanceof Set) {
                    list = Array.from(rawNodes)
                } else if (typeof rawNodes === 'object' && rawNodes !== null) {
                    list = Object.values(rawNodes)
                }

                if (list.length > 0) {
                    const urlParams = new URLSearchParams(window.location.search)
                    const currentDir = urlParams.get('dir') || '/'

                    const parsedFiles = list
                        .map(item => parseSelectedNode(item, currentDir))
                        .filter(f => f && f.name && f.name !== 'undefined' && f.name !== '')

                    if (parsedFiles.length > 0) {
                        console.log(`[BatchRenamer] ${parsedFiles.length} Datei(en) erfolgreich via selectedNodes erfasst:`, parsedFiles)
                        return parsedFiles
                    }
                }
            }
            comp = comp.$parent
        }
    } catch (err) {
        console.warn('[BatchRenamer] Fallback auf DOM:', err)
    }

    return getSelectedFilesFromDOMFallback()
}

function getSelectedFilesFromDOMFallback() {
    const selected = []
    const checkboxes = Array.from(document.querySelectorAll('input[type="checkbox"]:checked'))

    checkboxes.forEach(cb => {
        if (cb.id === 'select_all_files' || cb.closest('thead, .files-list__header')) {
            return
        }

        const row = cb.closest('tr, [role="row"], .files-list__row, li')
        if (!row) return

        let id = row.getAttribute('data-id') || 
                 row.getAttribute('data-file-id') || 
                 row.getAttribute('data-cy-files-list-row-fileid') ||
                 row.dataset?.id || 
                 row.dataset?.fileId

        let name = row.getAttribute('data-file') || row.dataset?.file
        if (!name) {
            const nameEl = row.querySelector('.innernametext, .nametext, .files-list__row-name, .file-name')
            if (nameEl) {
                name = nameEl.textContent.trim()
            }
        }

        if (name) {
            name = name.replace(/\s+\./g, '.')
            const urlParams = new URLSearchParams(window.location.search)
            const currentDir = urlParams.get('dir') || '/'
            const fullPath = (currentDir.endsWith('/') ? currentDir : currentDir + '/') + name

            selected.push({
                id: id ? String(id) : name,
                name: String(name),
                path: fullPath
            })
        }
    })

    return selected
}

function openRenameDialog(files) {
    if (!modalInstance) {
        const Component = Vue.extend(RenameModal)
        modalInstance = new Component({
            propsData: {
                show: true,
                files: files
            }
        }).$mount(modalMount)

        modalInstance.$on('close', () => {
            modalInstance.show = false
        })
    } else {
        modalInstance.files = files
        modalInstance.show = true
    }
}

function attachRenameButton() {
    if (document.getElementById('batch-renamer-btn')) {
        return
    }

    const allButtons = Array.from(document.querySelectorAll('button'))
    const refButton = allButtons.find(b => {
        const text = (b.textContent || '').trim()
        return text.includes('Verschieben oder kopieren') || text.includes('Move or copy')
    })

    if (!refButton || !refButton.parentElement) {
        return
    }

    const btn = refButton.cloneNode(true)
    btn.id = 'batch-renamer-btn'
    btn.title = t('batch_renamer', 'Batch rename selected files')

    const textEl = btn.querySelector('.button-vue__text, span:last-child')
    if (textEl) {
        textEl.textContent = t('batch_renamer', 'Batch rename')
    }

    const iconEl = btn.querySelector('.button-vue__icon, svg')
    if (iconEl) {
        iconEl.innerHTML = `
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display: inline-block; vertical-align: middle;">
                <path d="M4 6h16M4 12h10M4 18h16M18 10l3 2-3 2"/>
            </svg>
        `
    }

    btn.addEventListener('click', (e) => {
        e.preventDefault()
        e.stopPropagation()
        const selectedFiles = getSelectedFiles()
        if (selectedFiles.length < 2) {
            alert(t('batch_renamer', 'Please select at least two files to rename.'))
            return
        }
        openRenameDialog(selectedFiles)
    })

    refButton.parentElement.insertBefore(btn, refButton.nextSibling)
}

let debounceTimer = null
const observer = new MutationObserver(() => {
    if (debounceTimer) return
    debounceTimer = requestAnimationFrame(() => {
        attachRenameButton()
        debounceTimer = null
    })
})

observer.observe(document.body, { childList: true, subtree: true })
attachRenameButton()
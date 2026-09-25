<?php
namespace OCA\BatchRenamer\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use OCP\Files\IRootFolder;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IUserSession;

class RenameController extends Controller {
    private IRootFolder $rootFolder;
    private IUserSession $userSession;

    public function __construct(string $appName, IRequest $request, IRootFolder $rootFolder, IUserSession $userSession) {
        parent::__construct($appName, $request);
        $this->rootFolder = $rootFolder;
        $this->userSession = $userSession;
    }

    #[NoAdminRequired]
    public function process(array $files, string $pattern, int $startNumber = 1, string $dir = '/'): DataResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new DataResponse(['error' => 'Nicht autorisiert.'], 401);
        }

        if (empty($files)) {
            return new DataResponse(['error' => 'Keine Dateien ausgewählt.'], 400);
        }

        $cleanPattern = $this->sanitizePattern($pattern);
        if (empty($cleanPattern) || !str_contains($cleanPattern, '#')) {
            return new DataResponse([
                'error' => 'Das Namensmuster muss mindestens ein "#" enthalten.'
            ], 400);
        }

        preg_match('/#+/', $cleanPattern, $matches);
        $hashPlaceholder = $matches[0];
        $digits = strlen($hashPlaceholder);

        $userFolder = $this->rootFolder->getUserFolder($user->getUID());
        $currentDirClean = trim($dir, '/');
        $resolvedNodes = [];
        $selectedNodeIds = [];

        // 1. Alle ausgewählten Dateien auflösen
        foreach ($files as $fileItem) {
            $node = null;
            $fileId = is_array($fileItem) ? ($fileItem['id'] ?? null) : $fileItem;
            $filePath = is_array($fileItem) ? ($fileItem['path'] ?? null) : null;
            $fileName = is_array($fileItem) ? ($fileItem['name'] ?? null) : (is_string($fileItem) ? $fileItem : '');

            $fileName = trim(preg_replace('/\s+\./', '.', (string)$fileName));
            $filePath = trim(preg_replace('/\s+\./', '.', (string)$filePath));

            if ($fileId && is_numeric($fileId) && (int)$fileId > 0) {
                try {
                    $nodes = $userFolder->getById((int)$fileId);
                    if (!empty($nodes)) {
                        $node = $nodes[0];
                    }
                } catch (\Throwable $t) {}
            }

            if (!$node && !empty($fileName)) {
                $targetRelPath = !empty($currentDirClean) ? $currentDirClean . '/' . $fileName : $fileName;
                try {
                    $node = $userFolder->get($targetRelPath);
                } catch (\Throwable $t) {}
            }

            if (!$node && !empty($filePath)) {
                $cleanP = ltrim($filePath, '/');
                if (!empty($fileName) && !str_ends_with($cleanP, $fileName)) {
                    $cleanP .= '/' . $fileName;
                }
                try {
                    $node = $userFolder->get($cleanP);
                } catch (\Throwable $t) {}
            }

            if (!$node) {
                return new DataResponse([
                    'error' => 'Datei "' . ($fileName ?: $fileId) . '" konnte nicht im Speicher gefunden werden.'
                ], 404);
            }

            // Ordner selbst ignorieren
            if (!empty($currentDirClean)) {
                $expectedFolder = $userFolder->getPath() . '/' . $currentDirClean;
                if ($node->getPath() === $expectedFolder) {
                    continue;
                }
            }

            if (!$node->isUpdateable()) {
                return new DataResponse([
                    'error' => 'Keine Schreibberechtigung für: ' . $node->getName()
                ], 403);
            }

            $resolvedNodes[] = $node;
            $selectedNodeIds[] = $node->getId();
        }

        if (empty($resolvedNodes)) {
            return new DataResponse(['error' => 'Keine gültigen Dateien zum Umbenennen gefunden.'], 400);
        }

        // 2. Zielnamen vorbereiten & Kollisionsprüfung durchführen
        $nodesToRename = [];
        $generatedNames = [];
        $currentCounter = max(0, $startNumber);

        foreach ($resolvedNodes as $node) {
            $ext = pathinfo($node->getName(), PATHINFO_EXTENSION);
            $numberPart = str_pad((string)$currentCounter, $digits, '0', STR_PAD_LEFT);
            $targetBaseName = str_replace($hashPlaceholder, $numberPart, $cleanPattern);
            $targetName = !empty($ext) ? $targetBaseName . '.' . $ext : $targetBaseName;

            if (strlen($targetName) > 255) {
                return new DataResponse([
                    'error' => 'Der neue Dateiname überschreitet 255 Zeichen: ' . $targetName
                ], 400);
            }

            // Kollisionsprüfung innerhalb der neuen Auswahlliste
            if (in_array($targetName, $generatedNames, true)) {
                return new DataResponse([
                    'error' => 'Muster erzeugt doppelte Dateinamen: "' . $targetName . '".'
                ], 400);
            }
            $generatedNames[] = $targetName;

            $parent = $node->getParent();

            // Kollisionsprüfung mit bereits im Ordner existierenden Dateien
            if ($parent instanceof Folder && $parent->nodeExists($targetName)) {
                $existingNode = $parent->get($targetName);
                // Wenn die existierende Datei NICHT zu den markierten gehört, droht Überschreiben!
                if (!in_array($existingNode->getId(), $selectedNodeIds, true)) {
                    return new DataResponse([
                        'error' => 'Kollision verhindert: Die Datei "' . $targetName . '" existiert bereits im Ordner.'
                    ], 409);
                }
            }

            $nodesToRename[] = [
                'node' => $node,
                'parentPath' => $parent->getPath(),
                'originalName' => $node->getName(),
                'targetName' => $targetName,
                'tempName' => '_tmp_rename_' . bin2hex(random_bytes(8)) . (!empty($ext) ? '.' . $ext : '')
            ];

            $currentCounter++;
        }

        // 3. Ausführung mit Rollback-Absicherung
        $movedToTemp = [];
        try {
            // Phase 1: Temporär umbenennen
            foreach ($nodesToRename as $item) {
                $item['node']->move($item['parentPath'] . '/' . $item['tempName']);
                $movedToTemp[] = $item;
            }
            // Phase 2: Endgültig umbenennen
            foreach ($nodesToRename as $item) {
                $item['node']->move($item['parentPath'] . '/' . $item['targetName']);
            }
        } catch (\Throwable $e) {
            // Automatischer Rollback im Fehlerfall
            foreach ($movedToTemp as $item) {
                try {
                    if ($item['node']->getName() === $item['tempName']) {
                        $item['node']->move($item['parentPath'] . '/' . $item['originalName']);
                    }
                } catch (\Throwable $rb) {}
            }

            return new DataResponse([
                'error' => 'Fehler bei der Umbenennung (Änderungen wurden zurückgesetzt): ' . $e->getMessage()
            ], 500);
        }

        return new DataResponse([
            'success' => true,
            'count' => count($nodesToRename)
        ]);
    }

    private function sanitizePattern(string $pattern): string {
        $sanitized = str_replace(['/', '\\', "\0", '..', ':', '*', '?', '"', '<', '>', '|'], '-', $pattern);
        $sanitized = preg_replace('/-+/', '-', $sanitized);
        return trim($sanitized, " .-_\t\n\r\0\x0B");
    }

    #[NoAdminRequired]
    public function undo(array $items): DataResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new DataResponse(['error' => 'Nicht autorisiert.'], 401);
        }

        if (empty($items)) {
            return new DataResponse(['error' => 'Keine Daten zum Zurücksetzen vorhanden.'], 400);
        }

        $userFolder = $this->rootFolder->getUserFolder($user->getUID());
        $revertedCount = 0;

        foreach ($items as $item) {
            $fileId = $item['id'] ?? null;
            $oldName = $item['oldName'] ?? null;
            $newName = $item['newName'] ?? null;
            $path = $item['path'] ?? null;

            if (!$oldName) {
                continue;
            }

            $node = null;

            // 1. Versuch: Über eindeutige Nextcloud File-ID finden
            if ($fileId && is_numeric($fileId) && (int)$fileId > 0) {
                try {
                    $nodes = $userFolder->getById((int)$fileId);
                    if (!empty($nodes)) {
                        $node = $nodes[0];
                    }
                } catch (\Throwable $t) {}
            }

            // 2. Fallback: Über Pfad/Neuen Namen im Ordner finden
            if (!$node && !empty($path)) {
                try {
                    $dir = dirname($path);
                    $targetPath = ($dir === '.' || $dir === '/' || empty($dir)) ? $newName : trim($dir, '/') . '/' . $newName;
                    $node = $userFolder->get($targetPath);
                } catch (\Throwable $t) {}
            }

            // Zurückbenennen auf den alten Namen
            if ($node) {
                try {
                    $parent = $node->getParent();
                    if ($parent instanceof Folder && !$parent->nodeExists($oldName)) {
                        $node->move($parent->getPath() . '/' . $oldName);
                        $revertedCount++;
                    }
                } catch (\Throwable $t) {}
            }
        }

        return new DataResponse([
            'success' => true,
            'reverted' => $revertedCount
        ]);
    }
}
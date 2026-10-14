<?php
/** One editor/TeX dialog shared by normal and exam question authoring. */
if(!empty($schoolQuestionEditorLoaded))return;
$schoolQuestionEditorLoaded=true;
?>
<script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js" referrerpolicy="origin"></script>
<script src="<?= APP_URL ?>/assets/js/question-editor.js?v=<?= (int)filemtime(__DIR__.'/../assets/js/question-editor.js') ?>"></script>
<!-- Mathematics in TeX Dialog Modal -->
<div class="modal fade" id="texModal" tabindex="-1" aria-hidden="true" style="z-index: 9999;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg border-0">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold text-dark"><i class="fas fa-square-root-alt text-primary"></i> Mathematics in TeX</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label for="texInput" class="form-label fw-bold text-secondary">Write your TeX here</label>
                    <textarea id="texInput" class="form-control font-monospace" rows="4" placeholder="e.g. x = {-b \pm \sqrt{b^2-4ac} \over 2a}"></textarea>
                </div>
                <div class="mb-3 text-end">
                    <a href="https://en.wikibooks.org/wiki/LaTeX/Mathematics" target="_blank" rel="noopener" class="text-decoration-none small text-primary fw-bold">
                        TeX documentation <i class="fas fa-external-link-alt small"></i>
                    </a>
                </div>
                <div class="mb-3 p-3 bg-light rounded border text-center" style="min-height: 100px;">
                    <div class="text-muted small mb-2 border-bottom pb-1 fw-bold">Live MathJax Preview</div>
                    <div id="texPreview" style="font-size: 1.3rem; overflow-x: auto; padding: 10px 0; color: #2c3e50;"></div>
                </div>
                <div>
                    <label for="texDisplayMode" class="form-label fw-bold text-secondary">Display Mode</label>
                    <select id="texDisplayMode" class="form-select">
                        <option value="inline">Inline Math: \( ... \)</option>
                        <option value="block">Block Math (Centered): \[ ... \]</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer bg-light border-top-0 d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success px-4 fw-bold" id="btnInsertTex">OK</button>
            </div>
        </div>
    </div>
</div>

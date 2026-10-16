<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}
?>
<div class="modal" data-modal hidden>
    <div class="modal__scrim" data-modal-close></div>
    <div class="modal__panel" role="dialog" aria-modal="true" aria-labelledby="modal-title">
        <header class="modal__head">
            <h2 class="modal__title" id="modal-title" data-modal-title>标题</h2>
            <button class="iconbtn" type="button" data-modal-close aria-label="关闭"><?= lucide('x') ?></button>
        </header>
        <div class="modal__body" data-modal-body></div>
        <footer class="modal__foot" data-modal-foot></footer>
    </div>
</div>

<div class="toasts" data-toasts aria-live="polite"></div>

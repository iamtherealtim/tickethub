<?php /* Canned-response picker for the reply box. The list is loaded by editor.js from app/canned.json
         (global + the agent's group + their own). "Save as response" files the current draft as a personal one. */ ?>
<div data-canned-picker class="relative flex items-center gap-1">
  <button type="button" data-canned-toggle class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-lg border border-line bg-white text-[12px] font-medium text-ink-500 hover:bg-canvas" title="Insert a canned response (type /shortcut then Tab in the box)">
    <?= th_icon('note', 'w-3.5 h-3.5 text-faint') ?>Insert response<?= th_icon('down', 'w-3 h-3 text-faint') ?></button>
  <?php if (! empty($cannedScoped)): ?>
  <button type="button" data-modal="saveCanned" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-lg border border-line bg-white text-[12px] font-medium text-ink-500 hover:bg-canvas" title="Save what you have written as a personal response">
    <?= th_icon('plus', 'w-3.5 h-3.5 text-faint') ?><span class="hidden sm:inline">Save as response</span></button>
  <?php endif ?>
  <div data-canned-pop class="hidden absolute right-0 top-9 z-30 w-[360px] max-w-[92vw] bg-white border border-line rounded-xl shadow-pop overflow-hidden">
    <div class="p-2 border-b border-line">
      <input data-canned-search placeholder="Search responses or /shortcut…" autocomplete="off"
        class="w-full h-8 px-2.5 rounded-lg border border-line bg-canvas text-[13px] placeholder:text-faint focus:bg-white">
    </div>
    <div data-canned-list class="max-h-[300px] overflow-y-auto divide-y divide-line"></div>
    <div class="px-3 py-2 bg-canvas border-t border-line text-[11px] text-faint flex items-center gap-2">
      <span>↑↓ pick · Enter inserts · placeholders like <code class="font-mono">{{requester_first_name}}</code> are filled in</span>
    </div>
  </div>
</div>

<?php if (! empty($cannedScoped)): ?>
<template id="tpl-saveCanned">
  <form method="post" action="<?= site_url('app/canned') ?>" data-modal-title="Save as a personal response" data-modal-sub="Only you will see it in the picker. Placeholders: {{requester_first_name}} {{requester_name}} {{agent_name}} {{ticket_code}} {{ticket_subject}}" data-submit="Save response">
    <?= csrf_field() ?>
    <div class="grid sm:grid-cols-[1fr_150px] gap-3.5">
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Title<span class="text-alert"> *</span></label>
        <input name="title" required maxlength="80" placeholder="e.g. Ask for a screenshot" class="w-full h-9 px-2.5 rounded-lg border border-line text-[13px] focus:border-brand"></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Shortcut</label>
        <div class="flex items-center h-9 rounded-lg border border-line text-[13px] focus-within:border-brand"><span class="pl-2.5 text-faint font-mono">/</span>
          <input name="shortcut" maxlength="40" placeholder="screenshot" class="flex-1 min-w-0 h-full px-1.5 rounded-r-lg border-0 text-[13px] font-mono focus:ring-0"></div></div>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Body<span class="text-alert"> *</span></label>
        <textarea name="body" rows="6" required class="w-full px-2.5 py-2 rounded-lg border border-line text-[13px] leading-relaxed focus:border-brand"></textarea></div>
    </div>
  </form>
</template>
<?php endif ?>

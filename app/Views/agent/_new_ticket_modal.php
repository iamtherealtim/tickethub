<?php /* New-ticket modal template, included by layouts/agent.php. Vars: requesters, groups, assignableAgents, ticketFields */ ?>
<template id="tpl-newTicket">
  <form method="post" action="<?= site_url('app/tickets') ?>" enctype="multipart/form-data" data-modal-title="New ticket" data-modal-sub="Raise on behalf of someone who called or walked up" data-modal-width="max-w-2xl" data-submit="Create ticket">
    <?= csrf_field() ?>
    <div class="grid sm:grid-cols-2 gap-3.5">
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Subject<span class="text-alert"> *</span></label>
        <input name="subject" required placeholder="One line the requester would recognise" autocomplete="off"
          data-kb-suggest="<?= site_url('app/kb/suggest') ?>"
          class="w-full h-9 px-2.5 rounded-lg border border-line bg-white text-[13px] placeholder:text-faint focus:border-brand">
        <div data-kb-results class="mt-2 empty:mt-0"></div></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Requester</label>
        <select name="requester_id" class="w-full h-9 px-2.5 rounded-lg border border-line bg-white text-[13px]">
          <?php foreach ($requesters as $u): ?><option value="<?= $u['id'] ?>"><?= esc($u['name']) ?> — <?= esc($u['dept']) ?></option><?php endforeach ?>
        </select></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Source</label>
        <select name="source" class="w-full h-9 px-2.5 rounded-lg border border-line bg-white text-[13px]">
          <?php foreach (TH_SOURCES as $s): ?><option><?= $s ?></option><?php endforeach ?>
        </select></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Type</label>
        <select name="type" class="w-full h-9 px-2.5 rounded-lg border border-line bg-white text-[13px]">
          <option>Incident</option><option>Service request</option>
        </select></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Category</label>
        <select name="category" class="w-full h-9 px-2.5 rounded-lg border border-line bg-white text-[13px]">
          <?php foreach (TH_CATEGORIES as $c): ?><option><?= $c ?></option><?php endforeach ?>
        </select></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Priority</label>
        <select name="priority" class="w-full h-9 px-2.5 rounded-lg border border-line bg-white text-[13px]">
          <option>Urgent</option><option>High</option><option selected>Medium</option><option>Low</option>
        </select></div>
      <div><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Group</label>
        <select name="group_id" class="w-full h-9 px-2.5 rounded-lg border border-line bg-white text-[13px]">
          <option value="">Automatic — route by rules</option>
          <?php foreach ($groups as $g): ?><option value="<?= $g['id'] ?>"><?= esc($g['name']) ?></option><?php endforeach ?>
        </select></div>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Assign to</label>
        <select name="agent_id" class="w-full h-9 px-2.5 rounded-lg border border-line bg-white text-[13px]">
          <option value="">Leave unassigned</option>
          <?php foreach ($assignableAgents as $a): ?><option value="<?= $a['id'] ?>"><?= esc($a['name']) ?></option><?php endforeach ?>
        </select></div>
      <?php foreach (($ticketFields ?? []) as $tf): if ((int) $tf['agents'] === 1): ?>
        <?php if ($tf['type'] === 'Paragraph'): ?><div class="sm:col-span-2"><?= th_custom_field($tf) ?></div>
        <?php else: ?><?= th_custom_field($tf) ?><?php endif ?>
      <?php endif; endforeach ?>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Description<span class="text-alert"> *</span></label>
        <textarea name="body" rows="4" required placeholder="What happened, what they have already tried, and what good looks like." class="w-full px-2.5 py-2 rounded-lg border border-line text-[13px] leading-relaxed placeholder:text-faint focus:border-brand"></textarea></div>
      <div class="sm:col-span-2"><label class="block text-[12px] font-medium text-ink-500 mb-1.5">Attachments</label>
        <input type="file" name="files[]" multiple class="w-full text-[12.5px] text-muted file:mr-3 file:h-8 file:px-3 file:rounded-lg file:border file:border-line file:bg-white file:text-[12.5px] file:font-medium file:text-ink-500 file:cursor-pointer">
        <p class="text-[11.5px] text-faint mt-1">Up to 10 MB each — images, PDF, Office, logs, zip.</p></div>
    </div>
  </form>
</template>

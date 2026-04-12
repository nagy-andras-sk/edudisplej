<?php
$editor_header_is_default = !empty($is_default_group);
$editor_header_is_content_only = !empty($is_content_only_mode);
?>
<div class="planner-column-header" style="display:flex; align-items:center; justify-content:space-between; gap:8px; text-transform:none; letter-spacing:0; font-size:11px;">
    <span><?php echo htmlspecialchars(t_def('group_loop.edited_loop', 'Edited loop')); ?>: <strong id="active-loop-inline-name">—</strong><span id="active-loop-inline-schedule" style="margin-left:6px; font-weight:400; color:#425466;"></span></span>
    <?php if (!$editor_header_is_default && !$editor_header_is_content_only): ?>
        <span style="display:flex; gap:6px; align-items:center;">
                <button class="btn" type="button" onclick="publishLoopPlan()" style="padding:4px 8px; font-size:11px;">💾 <?php echo htmlspecialchars(t_def('common.save', 'Save')); ?></button>
            <button class="btn" type="button" onclick="renameActiveLoopStyle()" style="padding:4px 8px; font-size:11px;">✏️ <?php echo htmlspecialchars(t_def('group_loop.rename', 'Rename')); ?></button>
            <button class="btn" type="button" onclick="duplicateActiveLoopStyle()" style="padding:4px 8px; font-size:11px;">📄 <?php echo htmlspecialchars(t_def('group_loop.duplicate', 'Duplicate')); ?></button>
        </span>
    <?php endif; ?>
</div>

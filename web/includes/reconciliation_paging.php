<?php if($paging['pages']>1):?><nav aria-label="AM candidate pages" class="my-3">
<?php if($paging['page']>1):?><a class="btn btn-outline-primary rw-leave" href="<?=am_workshop_h(am_workshop_url($partId,$q,$paging['page']-1))?>">Previous 12 AM candidates</a><?php endif?>
<?php if($paging['page']<$paging['pages']):?><a class="btn btn-outline-primary rw-leave" href="<?=am_workshop_h(am_workshop_url($partId,$q,$paging['page']+1))?>">Next 12 AM candidates</a><?php endif?>
</nav><?php endif?>

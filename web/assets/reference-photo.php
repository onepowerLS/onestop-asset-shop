<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/country_scope.php';
require_once __DIR__ . '/../config/pr_receipts.php';
require_once __DIR__ . '/../config/reference_photos.php';
require_once __DIR__ . '/../config/reconciliation.php';
require_login(); am_ensure_country_scope_from_session();
if (!am_has_privilege_action('view_assets') && !am_can_operate_assets() && !am_is_manager_role()) { http_response_code(403); exit('AM viewing access required.'); }
if (isset($_GET['id'])) {
    $id=(string)$_GET['id']; if (!preg_match('/^[a-f0-9]{32}$/D',$id)) { http_response_code(404); exit; }
    $meta=am_firestore_get_document('am_part_media',$id);
    $file=am_reference_photo_root().'/'.$id.'.jpg';
    if (!$meta || empty($meta['approved']) || !is_file($file)) { http_response_code(404); exit('Photo unavailable'); }
    header('Content-Type: image/jpeg'); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store'); readfile($file); exit;
}
if (isset($_GET['definition'])) {
    $definitionId=(string)$_GET['definition'];
    if (!preg_match('/^[a-zA-Z0-9_.-]{1,160}$/D',$definitionId)) { http_response_code(404); exit('Definition not found'); }
    $definition=am_firestore_get_document('am_part_definitions',$definitionId);
    if (!$definition || empty($definition['canonical_approved'])) { http_response_code(404); exit('Published definition not found'); }
    $photos=array_values(array_filter(am_firestore_get_collection('am_part_media',1000),fn($m)=>!empty($m['approved']) && ($m['definition_id']??'')===$definitionId));
    $page_title='Shared part reference photos'; include __DIR__.'/../includes/header.php';
    echo '<h1>'.am_workshop_h($definition['name']).'</h1><p>Shared reference photos, available across countries. These identify the part, not local stock.</p>';
    if (!$photos) echo '<p>No shared reference photographs yet.</p>';
    foreach($photos as $photo) echo '<figure><img style="max-width:100%;max-height:360px" src="reference-photo.php?id='.rawurlencode($photo['id']).'" alt="'.am_workshop_h($photo['caption']).'"><figcaption>'.am_workshop_h($photo['caption']).'</figcaption></figure>';
    include __DIR__.'/../includes/footer.php'; exit;
}
$assetId=(string)($_GET['asset']??'');
if (!preg_match('/^[a-zA-Z0-9_-]{1,150}$/D',$assetId)) { http_response_code(404); exit('Item not found'); }
$asset=am_firestore_get_document('am_core_assets',$assetId);if(!$asset){http_response_code(404);exit('Item not found');}
am_require_asset_visible($asset,am_get_countries());
$_SESSION['am_receipt_csrf']??=bin2hex(random_bytes(32));$error='';$message='';
$all=am_firestore_get_collection('am_part_media',1000);
$photos=array_values(array_filter($all,fn($m)=>!empty($m['approved']) && (($m['asset_id']??'')===$assetId || (!empty($asset['definition_id']) && ($m['definition_id']??'')===$asset['definition_id']))));
if($_SERVER['REQUEST_METHOD']==='POST') {
    $file='';
    try {
        if(!am_is_manager_role() || !am_receipt_csrf_valid()) throw new RuntimeException('An AM approver with a valid session must approve the reference photo.');
        if(!am_asset_passes_org_scope($asset,am_get_countries())) throw new RuntimeException('Item outside your scope.');
        $caption=trim((string)($_POST['caption']??''));
        if(strlen($caption)<10 || strlen($caption)>300 || !isset($_POST['verified'])) throw new RuntimeException('Write a descriptive caption and confirm that the photo accurately represents this item.');
        if(count($photos)>=5) throw new RuntimeException('This item already has five reference photos. Reuse them; ask the catalogue steward if one needs replacement.');
        $upload=$_FILES['photo']??[];
        $uploadErr=(int)($upload['error']??UPLOAD_ERR_NO_FILE);
        if($uploadErr===UPLOAD_ERR_INI_SIZE || $uploadErr===UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException('That photo is larger than the server upload limit. Use a smaller JPEG/PNG/WebP, or wait for the phone to finish compressing the preview.');
        }
        if($uploadErr===UPLOAD_ERR_NO_FILE) throw new RuntimeException('Choose a JPEG, PNG or WebP photo first.');
        if($uploadErr!==UPLOAD_ERR_OK) throw new RuntimeException('The photo did not upload completely. Check your connection and try again.');
        if(($upload['size']??0)>5*1024*1024 || !is_uploaded_file($upload['tmp_name']??'')) throw new RuntimeException('Choose a JPEG, PNG or WebP photo up to 5 MB.');
        if(!is_dir(am_reference_photo_root()) || !is_writable(am_reference_photo_root())) throw new RuntimeException('Photo storage is not configured. Ask the AM administrator.');
        $id=bin2hex(random_bytes(16));$file=am_reference_photo_root().'/'.$id.'.jpg';
        $prepared=am_reference_photo_prepare($upload['tmp_name'],$file);
        foreach($photos as $photo) if(($photo['sha256']??'')===$prepared['sha256']) throw new RuntimeException('This reference photo is already present. Reuse it instead.');
        $result=am_firestore_create_document('am_part_media',array_merge($prepared,[
          'asset_id'=>$assetId,'definition_id'=>$asset['definition_id']??null,'caption'=>$caption,'approved'=>true,
          'contributor_uid'=>$_SESSION['user_id']??'','created_at'=>date('c'),'content_type'=>'image/jpeg']),$id);
        if(empty($result['ok'])) throw new RuntimeException('The photo record could not be saved. Please retry.');
        header('Location: reference-photo.php?asset='.rawurlencode($assetId));exit;
    } catch(Throwable $e) {if($file!=='' && is_file($file)) unlink($file);$error=$e->getMessage();}
}
$page_title='Shared reference photos';include __DIR__.'/../includes/header.php';
?>
<h1>Shared reference photos</h1><h2><?=am_workshop_h($asset['name']??'')?></h2>
<p>Capture a clear overall view and, where useful, a close-up of the rating or model marking. Once this item has a shared definition, its approved photos can be reused in every country. They identify a part type; they are not proof of stock or receipt.</p>
<?php if($error):?><div role="alert" class="alert alert-danger"><?=am_workshop_h($error)?></div><?php endif?>
<div class="row"><?php foreach($photos as $photo):?><figure class="col-md-4"><img style="width:100%;height:220px;object-fit:contain" src="reference-photo.php?id=<?=rawurlencode($photo['id'])?>" alt="<?=am_workshop_h($photo['caption'])?>"><figcaption><?=am_workshop_h($photo['caption'])?><br><small>Contributed <?=am_workshop_h(substr($photo['created_at']??'',0,10))?></small><details><summary>Contributor reference</summary><?=am_workshop_h($photo['contributor_uid'])?></details></figcaption></figure><?php endforeach?></div>
<?php if(!$photos):?><p>No approved reference photo yet. The first useful set will help the other stores too.</p><?php else:?><p>Reference photos already exist. Upload only a useful missing view, not another copy.</p><?php endif?>
<?php if(am_is_manager_role()):?><form method="post" enctype="multipart/form-data" class="card p-4" id="ref-photo-form">
<input type="hidden" name="csrf" value="<?=am_workshop_h($_SESSION['am_receipt_csrf'])?>"><label for="photo">Take or choose a photo (JPEG, PNG or WebP). Phone photos are compressed automatically before upload (maximum 5 MB).</label><input class="form-control" id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp,image/*" required>
<img id="preview" style="display:none;max-height:250px;max-width:100%;object-fit:contain" alt="Your selected image preview">
<p id="photo-status" class="form-text text-muted" aria-live="polite"></p>
<label for="caption">Caption: what should colleagues notice?</label><input class="form-control" id="caption" name="caption" minlength="10" maxlength="300" required placeholder="Example: complete clamp, side view showing manufacturer and rating">
<label class="my-3"><input type="checkbox" name="verified" required> I approve this as a reference for this item. The model and rating are appropriate, and it contains no people or private paperwork.</label><button class="btn btn-primary" id="ref-photo-submit">Approve and save reference photo</button><p>The image is re-encoded and location metadata removed. Your account receives contributor credit. Photos do not establish technical equivalence by themselves.</p></form>
<script>
(function(){
  const input=document.getElementById('photo');
  const preview=document.getElementById('preview');
  const status=document.getElementById('photo-status');
  const form=document.getElementById('ref-photo-form');
  const submit=document.getElementById('ref-photo-submit');
  const MAX_EDGE=1600;
  const MAX_BYTES=5*1024*1024;
  function setStatus(t){status.textContent=t||'';}
  function revoke(){if(preview.dataset.url){URL.revokeObjectURL(preview.dataset.url);delete preview.dataset.url;}}
  function showPreview(blob){revoke();preview.dataset.url=URL.createObjectURL(blob);preview.src=preview.dataset.url;preview.style.display='block';}
  function loadImage(file){return new Promise((resolve,reject)=>{const url=URL.createObjectURL(file);const img=new Image();img.onload=()=>{URL.revokeObjectURL(url);resolve(img);};img.onerror=()=>{URL.revokeObjectURL(url);reject(new Error('Could not read that image. Convert HEIC to JPEG first.'));};img.src=url;});}
  function canvasToBlob(canvas,quality){return new Promise((resolve,reject)=>canvas.toBlob(b=>b?resolve(b):reject(new Error('Could not compress the photo.')),'image/jpeg',quality));}
  async function compress(file){
    if(!/^image\//.test(file.type||'') && !/\.(jpe?g|png|webp)$/i.test(file.name||'')) throw new Error('Choose a JPEG, PNG or WebP photo.');
    const img=await loadImage(file);
    const scale=Math.min(1, MAX_EDGE/Math.max(img.naturalWidth||img.width, img.naturalHeight||img.height));
    const canvas=document.createElement('canvas');
    canvas.width=Math.max(1,Math.round((img.naturalWidth||img.width)*scale));
    canvas.height=Math.max(1,Math.round((img.naturalHeight||img.height)*scale));
    const ctx=canvas.getContext('2d');
    ctx.fillStyle='#fff'; ctx.fillRect(0,0,canvas.width,canvas.height);
    ctx.drawImage(img,0,0,canvas.width,canvas.height);
    let quality=0.85, blob=await canvasToBlob(canvas,quality);
    while(blob.size>MAX_BYTES && quality>0.45){quality-=0.1; blob=await canvasToBlob(canvas,quality);}
    if(blob.size>MAX_BYTES) throw new Error('That photo is still larger than 5 MB after compression. Try a closer, simpler shot.');
    return new File([blob], (file.name||'reference').replace(/\.[^.]+$/,'')+'.jpg', {type:'image/jpeg', lastModified:Date.now()});
  }
  input.addEventListener('change', async (e)=>{
    const file=e.target.files&&e.target.files[0];
    if(!file){revoke();preview.style.display='none';setStatus('');return;}
    submit.disabled=true; setStatus('Preparing photo…');
    try{
      const out=await compress(file);
      const dt=new DataTransfer(); dt.items.add(out); input.files=dt.files;
      showPreview(out);
      setStatus('Ready to upload ('+Math.round(out.size/1024)+' KB). Add a caption and approve.');
    }catch(err){
      input.value=''; revoke(); preview.style.display='none';
      setStatus(err.message||'Could not prepare that photo.');
    }finally{submit.disabled=false;}
  });
  form.addEventListener('submit',()=>{if(submit.disabled) return false; submit.disabled=true; setStatus('Uploading…');});
})();
</script><?php else:?><p>Ask the AM approver to capture and approve a reference photo during the workshop.</p><?php endif?>
<p><a href="../admin/reconciliation.php">Return to joint workshop</a> · <a href="view.php?id=<?=rawurlencode($assetId)?>">Return to item</a></p>
<?php include __DIR__.'/../includes/footer.php'; ?>

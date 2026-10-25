<?php
session_start();
require_once __DIR__ . '/config.php';
function th($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ticketDate(?string $v): string { if (!$v) return 'Date to be announced'; try { return (new DateTime($v, new DateTimeZone('Asia/Kolkata')))->format('d M Y, h:i A'); } catch(Throwable $e) { return 'Date to be announced'; } }
$code = strtoupper(trim((string)($_GET['code'] ?? '')));
if (!preg_match('/^EF-[A-F0-9]{12}$/', $code)) { http_response_code(404); exit('Participant pass not found.'); }
try {
  $q=$pdo->prepare("SELECT r.id,r.event_id,r.organization_id,r.participant_name,r.email,r.phone,r.race_distance,r.registration_code,r.status,r.check_in_at,e.event_name,e.event_date,e.venue,e.slug,o.organization_name,o.logo_path FROM event_registrations r JOIN events e ON e.id=r.event_id JOIN organizations o ON o.id=r.organization_id WHERE r.registration_code=:code LIMIT 1");
  $q->execute([':code'=>$code]); $r=$q->fetch(PDO::FETCH_ASSOC);
} catch(Throwable $e) { error_log('Ticket lookup: '.$e->getMessage()); http_response_code(500); exit('Unable to load this participant pass.'); }
if (!$r) { http_response_code(404); exit('Participant pass not found.'); }
$ticketUrl='https://event.amit-neupane.com.np/ticket.php?code='.rawurlencode($code);
$logo = !empty($r['logo_path']) ? '/'.ltrim((string)$r['logo_path'],'/') : null;
$present = !empty($r['check_in_at']);
$checkedAt = $present ? ticketDate((string)$r['check_in_at']) : '';
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Participant Pass | <?=th($r['event_name'])?></title><style>
:root{--navy:#111a31;--purple:#5148d9;--lime:#d3f36b;--ink:#17213a;--muted:#79849a;--line:#e4e9f2}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at 10% 0,#e8e8ff,transparent 30%),#f3f5fb;color:var(--ink);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif}.wrap{width:min(760px,92%);margin:26px auto 50px}.top{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px}.top a{font-size:12px;font-weight:800;color:var(--purple);text-decoration:none}.top strong{font-size:19px}.top strong span{color:var(--purple)}.notice{padding:13px 15px;border-radius:12px;background:<?= $present ? '#e8f8ef' : '#f0efff' ?>;color:<?= $present ? '#126b47' : '#5148d9' ?>;font-size:12px;font-weight:800;margin-bottom:16px}.pass{overflow:hidden;background:#fff;border:1px solid var(--line);border-radius:22px;box-shadow:0 22px 54px #16203a18}.strip{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:13px 20px;background:var(--navy);color:var(--lime);font-size:9px;font-weight:950;letter-spacing:1.3px}.head{display:flex;align-items:center;gap:14px;padding:23px 23px 18px}.logo{width:62px;height:62px;flex:0 0 62px;border-radius:15px;border:1px solid var(--line);background:#fff;display:grid;place-items:center;overflow:hidden;color:var(--purple);font-weight:950}.logo img{width:100%;height:100%;object-fit:contain}.org{font-size:11px;color:var(--purple);font-weight:900}.event{font-size:clamp(20px,4vw,30px);letter-spacing:-.9px;line-height:1.15;font-weight:950;margin-top:5px;overflow-wrap:anywhere}.subtitle{font-size:11px;color:var(--muted);margin-top:7px}.body{display:grid;grid-template-columns:minmax(0,1fr) 175px;gap:20px;padding:20px 23px 24px}.details{display:grid;grid-template-columns:1fr 1fr;gap:17px 12px}.item small{display:block;font-size:8px;letter-spacing:1px;color:#8791a5;font-weight:950;margin-bottom:6px}.item strong{display:block;overflow-wrap:anywhere;font-size:12px;line-height:1.5}.item.full{grid-column:1/-1}.name strong{font-size:20px}.reg{grid-column:1/-1;padding:12px;border:1px dashed #aaa4ff;border-radius:12px;background:#f2f1ff}.reg strong{font-family:monospace;font-size:17px;letter-spacing:1px;color:#4037bf}.qr-wrap{border:1px solid var(--line);border-radius:15px;padding:14px 10px;display:flex;align-items:center;flex-direction:column;text-align:center;gap:9px;background:#fbfcff;align-self:start}.qr{width:142px;height:142px;background:#fff;display:grid;place-items:center}.qr img,.qr canvas{max-width:142px;max-height:142px}.qr-wrap strong{font-size:9px;letter-spacing:1px}.qr-wrap small{font-size:9px;color:var(--muted);line-height:1.5}.bottom{display:flex;justify-content:space-between;gap:10px;padding:13px 20px;border-top:1px dashed #dce2ec;color:var(--muted);font-size:9px}.actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:17px}.btn{min-height:44px;border-radius:11px;border:1px solid var(--line);padding:0 16px;background:#fff;font-weight:850;font-size:12px;color:var(--ink);cursor:pointer}.btn.dark{background:var(--navy);border-color:var(--navy);color:#fff}.footer{text-align:center;color:var(--muted);font-size:10px;line-height:1.6;margin-top:18px}@media(max-width:580px){.body{grid-template-columns:1fr;padding:17px 15px}.head{padding:18px 15px}.logo{width:51px;height:51px;flex-basis:51px}.details{gap:14px 10px}.qr-wrap{display:grid;grid-template-columns:100px 1fr;grid-template-rows:auto auto;align-items:center;text-align:left}.qr{width:100px;height:100px;grid-row:1/3}.qr img,.qr canvas{max-width:100px;max-height:100px}.strip{font-size:8px;padding:12px 14px}.bottom{flex-direction:column;padding:12px 15px}.actions .btn{flex:1;min-width:130px}}@media print{body{background:#fff}.top,.notice,.actions,.footer{display:none}.wrap{width:100%;margin:0}.pass{box-shadow:none}}
</style></head><body><main class="wrap"><div class="top"><strong>Event<span>Flow</span></strong><a href="/marathon.php?slug=<?=rawurlencode((string)$r['slug'])?>">View event page ↗</a></div>
<?php if ($present): ?><div class="notice">CHECKED IN · Present recorded at <?=th($checkedAt)?> IST</div><?php else: ?><div class="notice">PARTICIPANT PASS · Keep this QR code ready for event check-in</div><?php endif; ?>
<article class="pass" id="participant-pass"><div class="strip"><span>OFFICIAL PARTICIPANT PASS</span><span><?= $present ? 'PRESENT' : 'REGISTRATION PASS' ?></span></div><div class="head"><div class="logo"><?php if($logo):?><img src="<?=th($logo)?>" alt="Organization logo"><?php else:?>EF<?php endif;?></div><div><div class="org"><?=th($r['organization_name'])?></div><div class="event"><?=th($r['event_name'])?></div><div class="subtitle"><?=th(ticketDate((string)$r['event_date']))?> · <?=th($r['venue'])?></div></div></div><div class="body"><div class="details"><div class="item full name"><small>PARTICIPANT NAME</small><strong><?=th($r['participant_name'])?></strong></div><div class="item"><small>MOBILE NUMBER</small><strong><?=th($r['phone'])?></strong></div><div class="item"><small>RACE CATEGORY</small><strong><?=th($r['race_distance'])?></strong></div><div class="item full"><small>REGISTRATION STATUS</small><strong><?=th(ucfirst((string)$r['status']))?><?= $present ? ' · Present' : '' ?></strong></div><?php if($present):?><div class="item full"><small>CHECK-IN TIME (IST)</small><strong><?=th($checkedAt)?></strong></div><?php endif;?><div class="reg"><div class="item"><small>UNIQUE REGISTRATION NUMBER</small><strong><?=th($r['registration_code'])?></strong></div></div></div><div class="qr-wrap"><div id="ticket-qr" class="qr"></div><strong>SCAN AT CHECK-IN</strong><small>Organization staff can scan this QR or enter the registration number manually.</small></div></div><div class="bottom"><span>Powered by EventFlow</span><span>Present this pass at the race entrance.</span></div></article>
<div class="actions"><button class="btn" onclick="downloadPNG()">Download Image</button><button class="btn dark" onclick="downloadPDF()">Download PDF</button><button class="btn" onclick="window.print()">Print Pass</button></div><div class="footer">This QR contains a private registration lookup link. Please do not post your participant pass publicly.</div></main>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script><script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script><script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script><script>
const ticketUrl=<?=json_encode($ticketUrl)?>;if(window.QRCode)new QRCode(document.getElementById('ticket-qr'),{text:ticketUrl,width:142,height:142,colorDark:'#111a31',colorLight:'#ffffff',correctLevel:QRCode.CorrectLevel.M});
const emailOnce = new URLSearchParams(window.location.search).get('email_once') === '1';
async function renderPass(){if(!window.html2canvas){if(!emailOnce)window.print();return null;}return await html2canvas(document.getElementById('participant-pass'),{scale:2,backgroundColor:'#ffffff',useCORS:true,logging:false});}
async function downloadPNG(){try{const c=await renderPass();if(!c)return;const a=document.createElement('a');a.href=c.toDataURL('image/png');a.download='EventFlow-Pass-<?=th($code)?>.png';a.click();}catch(e){window.print();}}
async function makePdfBlob(c){const {jsPDF}=window.jspdf;const p=new jsPDF({orientation:'portrait',unit:'mm',format:'a4'});const pw=p.internal.pageSize.getWidth(),ph=p.internal.pageSize.getHeight(),margin=10;let w=pw-margin*2,h=c.height*w/c.width;if(h>ph-margin*2){h=ph-margin*2;w=c.width*h/c.height;}p.addImage(c.toDataURL('image/png'),'PNG',(pw-w)/2,margin,w,h);return p.output('blob');}
async function downloadPDF(){try{const c=await renderPass();if(!c)return;const {jsPDF}=window.jspdf;const p=new jsPDF({orientation:'portrait',unit:'mm',format:'a4'});const pw=p.internal.pageSize.getWidth(),ph=p.internal.pageSize.getHeight(),margin=10;let w=pw-margin*2,h=c.height*w/c.width;if(h>ph-margin*2){h=ph-margin*2;w=c.width*h/c.height;}p.addImage(c.toDataURL('image/png'),'PNG',(pw-w)/2,margin,w,h);p.save('EventFlow-Pass-<?=th($code)?>.pdf');}catch(e){window.print();}}
async function waitForPassAssets(){
  const images=Array.from(document.querySelectorAll('#participant-pass img'));
  await Promise.all(images.map(img=>img.complete?Promise.resolve():new Promise(resolve=>{img.onload=resolve;img.onerror=resolve;setTimeout(resolve,3000);} )));
  // qrcodejs may create the QR canvas shortly after the page scripts load.
  for(let i=0;i<40;i++){
    if(document.querySelector('#ticket-qr canvas, #ticket-qr img, #ticket-qr table'))break;
    await new Promise(resolve=>setTimeout(resolve,100));
  }
  if(!document.querySelector('#ticket-qr canvas, #ticket-qr img, #ticket-qr table'))throw new Error('QR code could not be rendered');
  await new Promise(resolve=>setTimeout(resolve,250));
}
async function autoEmailPass(){
  if(window.parent===window)return;
  try{
    await document.fonts.ready.catch(()=>{});
    await waitForPassAssets();
    let token='';
    for(let i=0;i<60;i++){
      try{token=window.parent.EventFlowPassEmailToken||'';}catch(e){token='';}
      if(token)break;
      await new Promise(resolve=>setTimeout(resolve,100));
    }
    if(!token)throw new Error('Missing one-time email token');
    const canvas=await renderPass();
    if(!canvas)throw new Error('Pass image generation is unavailable');
    const pngBlob=await new Promise(resolve=>canvas.toBlob(resolve,'image/png'));
    if(!pngBlob)throw new Error('PNG generation failed');
    const pdfBlob=await makePdfBlob(canvas);
    const form=new FormData();
    form.append('code', <?=json_encode($code)?>);
    form.append('token', token);
    form.append('pass_png', pngBlob, 'Participant-Pass.png');
    form.append('pass_pdf', pdfBlob, 'Participant-Pass.pdf');
    const response=await fetch('/send-pass-email.php',{method:'POST',body:form,credentials:'same-origin'});
    const result=await response.json().catch(()=>({sent:false}));
    try{window.parent.postMessage({type:'eventflow-pass-email-result',sent:!!result.sent},window.location.origin);}catch(e){}
  }catch(e){
    try{window.parent.postMessage({type:'eventflow-pass-email-result',sent:false},window.location.origin);}catch(ignore){}
  }
}
if(emailOnce){window.addEventListener('load',()=>setTimeout(autoEmailPass,350));}

</script></body></html>
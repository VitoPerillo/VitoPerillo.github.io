export class BrevoEmail {
  constructor(env){this.env=env;}
  async send(to,subject,html){
    if(!this.env.BREVO_API_KEY) throw new Error('email_not_configured');
    const senderEmail=this.env.EMAIL_FROM||'info@yoganostress.it';
    const senderName=this.env.EMAIL_FROM_NAME||'AHÓ ROMA';
    const r=await fetch('https://api.brevo.com/v3/smtp/email',{method:'POST',headers:{'content-type':'application/json','api-key':this.env.BREVO_API_KEY},body:JSON.stringify({sender:{email:senderEmail,name:senderName},to:[{email:to}],subject,htmlContent:html})});
    if(!r.ok) throw new Error(`brevo_http_${r.status}`);
    return true;
  }
}

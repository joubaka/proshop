from reportlab.pdfgen import canvas
from reportlab.lib.pagesizes import A4, landscape
from reportlab.lib.styles import ParagraphStyle
from reportlab.platypus import Paragraph
from reportlab.lib.colors import HexColor
from pypdf import PdfReader
out='output/pdf/court-lights-instructions-two-per-page.pdf'
W,H=landscape(A4)
c=canvas.Canvas(out,pagesize=(W,H))
c.setTitle('Court Lights - Customer Instructions - Two per A4 page')
steps=[
('Connect to the internet.', 'Turn on your phone\'s mobile data or connect to Wi-Fi.'),
('Open the website.', 'Open Safari, Chrome, or another browser and type <b>www.hscshop.co.za</b> into the address bar. You will automatically be directed to Court Lights.'),
('Create your account - first visit only.', 'Tap <b>"New here? Create a lights account"</b>. Enter your name, email address, password, and the same password again. Read the service terms and privacy notice, tick the acceptance box, and tap <b>"Create account"</b>.'),
('Verify your email if prompted.', 'Open your email inbox and tap the link in the verification email. Check your spam folder if it does not arrive. You can request another email by tapping <b>"Send a new verification link"</b> on Court Lights.'),
('Sign in.', 'Enter your email address and password, then tap <b>"Sign in"</b>. If you are already signed in after creating your account, continue to the next step.'),
('Add money to your Lights wallet.', 'Tap <b>"+ Top up"</b> or the <b>"Top up"</b> tab. Choose <b>R50, R100, or R200</b>, or enter another amount between <b>R10 and R5,000</b>. Tap <b>"Continue to PayFast"</b>.'),
('Complete payment and check your balance.', 'Follow the PayFast payment instructions, then return to Court Lights. Wait until your <b>Lights balance</b> shows the added money. Credit becomes available after PayFast confirms payment. If you have paid but your balance has not updated, contact the club before paying again.'),
('Choose your court and switch on.', 'Tap the <b>"Courts"</b> tab. Find your court, check the hourly price, and make sure it is available. Tap <b>"Switch on"</b>. Wait for confirmation and check that your court\'s lights are physically on.'),
('Play and monitor your session.', 'The screen shows your session cost and time remaining. You are charged for the time used at the displayed rate. <b>Closing the website or locking your phone does not stop your session.</b> Your session has an automatic cutoff. Adding money while playing does not extend the current cutoff; stop and restart the session to use the added credit.'),
('Switch off when you finish.', 'Tap <b>"Switch off [court name] &amp; finish"</b>. Wait for confirmation and check that the lights are physically off. Your final charge is recorded, and unused credit stays in your wallet. Tap <b>"Activity"</b> to review your session and payments. If you started two courts, switch each one off separately.')]
extras=[
('<b>For your next visit</b>', 'Open <b>www.hscshop.co.za</b> &gt; sign in if needed &gt; check your balance &gt; top up if necessary &gt; choose your court &gt; switch on &gt; switch off when finished.'),
('<b>Add Court Lights to your phone</b>', 'If <b>"Install Court Lights"</b> appears, tap it and follow the instructions. On an iPhone, follow <b>Share &gt; Add to Home Screen &gt; Add</b>. Afterwards, open Court Lights by tapping its icon. You still need an internet connection.'),
('<b>If you need help</b>', '<b>Forgotten password:</b> tap "Forgot your password?", enter your email, and tap "Send reset link".<br/><b>Switch-on button unavailable:</b> check that your email is verified, your wallet has credit, and the court is available.<br/><b>Court offline or lights not responding:</b> contact the club and provide the court number.<br/><b>Lights remain on after switching off:</b> contact the club immediately.')]
style=ParagraphStyle('body',fontName='Helvetica',fontSize=8.2,leading=10.1,textColor=HexColor('#181818'))
width=W/2-40
for offset in [0,W/2]:
 x=offset+20; y=H-22
 def para(text,gap=3):
  global y
  p=Paragraph(text,style); _,h=p.wrap(width,H)
  p.drawOn(c,x,y-h); y-=h+gap
 c.setFont('Helvetica-Bold',16); c.drawString(x,y-16,'How to use the court lights'); y-=24
 c.setFont('Helvetica-Bold',10); c.drawString(x,y-10,'www.hscshop.co.za'); y-=19
 para('You need a phone connected to the internet, an email address, and a payment method accepted by PayFast.',6)
 for i,(title,body) in enumerate(steps,1):
  para(f'<b>{i}. {title}</b> {body}',4)
 for title,body in extras:
  para(title+'<br/>'+body,5)
 print('Bottom position:',round(y,1))
 assert y>=18, 'Content exceeds printable margin'
c.setStrokeColor(HexColor('#aaaaaa')); c.setDash(3,4); c.line(W/2,15,W/2,H-15)
c.showPage();c.save()
r=PdfReader(out)
assert len(r.pages)==1
assert r.pages[0].extract_text().count('How to use the court lights')==2
print(out, '- one page, two complete copies')

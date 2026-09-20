<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Alberba Dental Clinic — Family Dentistry on Montalban Road</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,600;0,9..144,700;0,9..144,900;1,9..144,500;1,9..144,600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  /* ============ Tokens ============ */
  :root{
    --primary-pink:#d4739b;
    --light-pink:#f8e8f0;
    --accent-pink:#c85a87;
    --deep-rose:#7a3552;
    --ink:#241a1e;
    --ivory:#fffbf9;
    --white:#ffffff;
    --text-dark:#34242b;
    --text-gray:#7a6670;
    --border-color:#f0dbe4;

    --display: 'Fraunces', serif;
    --body: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;

    --radius-arch: 140px 140px 18px 18px;
    --radius-arch-sm: 90px 90px 14px 14px;
    --container: 1180px;
  }

  *{margin:0;padding:0;box-sizing:border-box;}

  html{scroll-behavior:smooth;}

  body{
    font-family:var(--body);
    color:var(--text-dark);
    background:var(--ivory);
    line-height:1.65;
    -webkit-font-smoothing:antialiased;
  }

  a{color:inherit;}
  img{display:block;max-width:100%;}
  ul{list-style:none;}

  :focus-visible{
    outline:3px solid var(--accent-pink);
    outline-offset:3px;
  }

  .wrap{max-width:var(--container);margin:0 auto;padding:0 2rem;}

  .eyebrow{
    font-family:var(--body);
    font-size:.78rem;
    font-weight:600;
    letter-spacing:.14em;
    text-transform:uppercase;
    color:var(--accent-pink);
    margin-bottom:.9rem;
  }

  h1,h2,h3{font-family:var(--display);font-weight:600;color:var(--text-dark);}
  h1 em, h2 em{font-style:italic;font-weight:500;color:var(--primary-pink);}

  .btn-primary, .btn-ghost, .btn-light{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:.85rem 1.9rem;
    border-radius:100px;
    font-family:var(--body);
    font-weight:600;
    font-size:.95rem;
    text-decoration:none;
    border:2px solid transparent;
    transition:transform .25s ease, box-shadow .25s ease, background-color .25s ease, color .25s ease;
    cursor:pointer;
  }
  .btn-primary{background:var(--primary-pink);color:var(--white);}
  .btn-primary:hover{background:var(--accent-pink);transform:translateY(-2px);box-shadow:0 10px 24px rgba(122,53,82,.28);}
  .btn-ghost{background:transparent;border-color:var(--primary-pink);color:var(--accent-pink);}
  .btn-ghost:hover{background:var(--primary-pink);color:var(--white);transform:translateY(-2px);}
  .btn-light{background:var(--white);color:var(--deep-rose);}
  .btn-light:hover{background:var(--light-pink);transform:translateY(-2px);}

  /* ============ Scallop divider (signature "smile line") ============ */
  .scallop{position:relative;height:44px;line-height:0;overflow:hidden;}
  .scallop svg{width:100%;height:100%;display:block;}
  .scallop.flip svg{transform:scaleY(-1);}

  /* ============ Nav ============ */
  nav{
    background:var(--white);
    padding:1.1rem 0;
    position:sticky;
    top:0;
    z-index:100;
    border-bottom:1px solid var(--border-color);
  }
  .nav-container{
    max-width:var(--container);
    margin:0 auto;
    padding:0 2rem;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:1.5rem;
    flex-wrap:wrap;
  }
  .logo{
    font-family:var(--display);
    font-size:1.35rem;
    font-weight:700;
    letter-spacing:.02em;
    color:var(--text-dark);
  }
  .logo span{color:var(--primary-pink);font-style:italic;font-weight:500;}
  .nav-links{display:flex;gap:2rem;align-items:center;}
  .nav-links a{
    text-decoration:none;
    color:var(--text-dark);
    font-weight:500;
    font-size:.95rem;
    padding:.3rem 0;
    position:relative;
  }
  .nav-links a::after{
    content:'';position:absolute;bottom:-2px;left:0;width:0;height:2px;
    background:var(--primary-pink);transition:width .25s ease;
  }
  .nav-links a:hover::after{width:100%;}
  .auth-buttons{display:flex;gap:.75rem;align-items:center;}
  .auth-buttons .btn-primary, .auth-buttons .btn-ghost{padding:.65rem 1.4rem;font-size:.88rem;}

  /* ============ Hero ============ */
  .hero{
    background:linear-gradient(180deg, var(--light-pink) 0%, #fdf1f6 100%);
    padding:5rem 0 0;
    position:relative;
  }
  .hero-inner{
    max-width:var(--container);
    margin:0 auto;
    padding:0 2rem 5rem;
    display:grid;
    grid-template-columns:1.05fr .95fr;
    gap:3.5rem;
    align-items:center;
  }
  .hero-content h1{
    font-size:3.4rem;
    line-height:1.08;
    margin-bottom:1.4rem;
    letter-spacing:-.01em;
  }
  .hero-sub{
    color:var(--text-gray);
    font-size:1.08rem;
    max-width:44ch;
    margin-bottom:2.2rem;
  }
  .hero-actions{display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:2.4rem;}
  .trust-chips{display:flex;flex-wrap:wrap;gap:.6rem 1.6rem;}
  .trust-chips li{
    font-size:.85rem;
    font-weight:600;
    color:var(--deep-rose);
    display:flex;
    align-items:center;
    gap:.5rem;
  }
  .trust-chips li::before{
    content:'';
    width:6px;height:6px;border-radius:50%;
    background:var(--primary-pink);
    display:inline-block;
  }

  .hero-visual{position:relative;}
  .hero-blob{
    position:absolute;
    inset:-6% -10%;
    background:linear-gradient(135deg, var(--primary-pink), var(--deep-rose));
    border-radius:62% 38% 55% 45% / 45% 55% 45% 55%;
    z-index:0;
    opacity:.9;
  }
  .hero-photo{
    position:relative;
    z-index:1;
    width:100%;
    height:520px;
    object-fit:cover;
    border-radius:var(--radius-arch);
    border:8px solid var(--white);
    box-shadow:0 24px 50px rgba(122,53,82,.25);
  }

  /* ============ About ============ */
  .about{padding:6rem 0;background:var(--ivory);}
  .about-head{text-align:center;max-width:640px;margin:0 auto 3.5rem;}
  .about-head h2{font-size:2.3rem;}
  .about-grid{
    display:grid;
    grid-template-columns:.85fr 1.15fr;
    gap:3rem;
    align-items:start;
  }
  .about-quote{
    background:var(--light-pink);
    border-radius:var(--radius-arch);
    padding:3.2rem 2.2rem 2.6rem;
    font-family:var(--display);
    font-style:italic;
    font-weight:500;
    font-size:1.35rem;
    line-height:1.5;
    color:var(--deep-rose);
  }
  .about-quote cite{
    display:block;
    margin-top:1.5rem;
    font-family:var(--body);
    font-style:normal;
    font-weight:600;
    font-size:.82rem;
    letter-spacing:.06em;
    text-transform:uppercase;
    color:var(--accent-pink);
  }
  .about-copy p{color:var(--text-gray);margin-bottom:1.2rem;font-size:1.02rem;}
  .about-facts{
    display:flex;
    gap:2.2rem;
    margin-top:1.8rem;
    padding-top:1.8rem;
    border-top:1px solid var(--border-color);
    flex-wrap:wrap;
  }
  .about-facts li strong{
    display:block;
    font-family:var(--display);
    font-size:1.9rem;
    color:var(--primary-pink);
    line-height:1;
    margin-bottom:.3rem;
  }
  .about-facts li{font-size:.85rem;color:var(--text-gray);font-weight:500;}

  /* ============ Services ============ */
  .services{background:var(--light-pink);padding:6rem 0;}
  .services-head{text-align:center;max-width:640px;margin:0 auto 3.5rem;}
  .services-head h2{font-size:2.3rem;}
  .services-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(250px,1fr));
    gap:2rem;
  }
  .service-card{
    background:var(--white);
    border-radius:22px;
    overflow:hidden;
    box-shadow:0 6px 20px rgba(122,53,82,.08);
    transition:transform .3s ease, box-shadow .3s ease;
  }
  .service-card:hover{transform:translateY(-6px);box-shadow:0 16px 34px rgba(122,53,82,.18);}
  .service-image{width:100%;height:190px;object-fit:cover;border-radius:var(--radius-arch-sm);padding:10px 10px 0;}
  .service-card-body{padding:1.4rem 1.6rem 1.8rem;}
  .service-card h3{color:var(--deep-rose);font-size:1.25rem;margin-bottom:.6rem;}
  .service-card p{color:var(--text-gray);font-size:.95rem;}

  /* ============ FAQ ============ */
  .faq{max-width:840px;margin:0 auto;padding:6rem 2rem;}
  .faq-head{text-align:center;max-width:600px;margin:0 auto 3rem;}
  .faq-head h2{font-size:2.3rem;}
  .faq-item{
    border-bottom:1px solid var(--border-color);
  }
  .faq-question{
    padding:1.4rem .2rem;
    cursor:pointer;
    font-weight:600;
    font-family:var(--display);
    font-size:1.08rem;
    color:var(--text-dark);
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:1rem;
  }
  .faq-toggle{
    flex-shrink:0;
    width:30px;height:30px;
    border-radius:50%;
    background:var(--light-pink);
    color:var(--accent-pink);
    display:flex;align-items:center;justify-content:center;
    font-size:1.2rem;font-weight:400;
    transition:transform .3s ease, background-color .3s ease;
  }
  .faq-answer{
    max-height:0;
    overflow:hidden;
    color:var(--text-gray);
    font-size:.98rem;
    transition:max-height .35s ease, padding .35s ease;
    padding:0 .2rem;
  }
  .faq-item.active .faq-answer{max-height:400px;padding:0 .2rem 1.6rem;}
  .faq-item.active .faq-toggle{transform:rotate(45deg);background:var(--primary-pink);color:var(--white);}

  /* ============ Contact ============ */
  .contact{background:var(--deep-rose);color:var(--white);padding:5.5rem 0;}
  .contact-inner{
    max-width:var(--container);
    margin:0 auto;
    padding:0 2rem;
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:3rem;
    align-items:center;
  }
  .contact h2{color:var(--white);font-size:2.2rem;margin-bottom:.9rem;}
  .contact-lede{color:#f0d9e2;font-size:1.02rem;margin-bottom:2rem;max-width:40ch;}
  .contact-list{display:flex;flex-direction:column;gap:1.1rem;}
  .contact-list li{display:flex;gap:1rem;align-items:flex-start;font-size:.98rem;}
  .contact-list .ico{
    width:38px;height:38px;flex-shrink:0;
    background:rgba(255,255,255,.14);
    border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    font-size:1.05rem;
  }
  .contact-card{
    background:rgba(255,255,255,.08);
    border:1px solid rgba(255,255,255,.18);
    border-radius:var(--radius-arch);
    padding:2.6rem 2.2rem;
    text-align:center;
  }
  .contact-card h3{color:var(--white);font-size:1.5rem;margin-bottom:.6rem;}
  .contact-card p{color:#f0d9e2;font-size:.95rem;margin-bottom:1.8rem;}

  /* ============ Footer ============ */
  footer{background:var(--ink);color:#cbb7bf;padding:2.4rem 0;text-align:center;font-size:.88rem;}
  footer strong{color:var(--white);}

  /* ============ Responsive ============ */
  @media (max-width:968px){
    .hero-inner{grid-template-columns:1fr;}
    .hero-photo{height:380px;}
    .about-grid{grid-template-columns:1fr;}
    .contact-inner{grid-template-columns:1fr;}
    .contact-card{order:-1;}
  }

  @media (max-width:640px){
    .nav-container{flex-direction:column;gap:1rem;}
    .nav-links{gap:1.2rem;flex-wrap:wrap;justify-content:center;}
    .hero-content h1{font-size:2.4rem;}
    .about-head h2, .services-head h2, .faq-head h2, .contact h2{font-size:1.8rem;}
    .about,.services{padding:4rem 0;}
    .faq{padding:4rem 1.4rem;}
  }

  @media (prefers-reduced-motion:reduce){
    *{transition:none !important; animation:none !important; scroll-behavior:auto !important;}
  }
</style>
</head>
<body>

<!-- Navigation -->
<nav>
  <div class="nav-container">
    <div class="logo">Alberba <span>Dental</span></div>
    <ul class="nav-links">
      <li><a href="#home">Home</a></li>
      <li><a href="#about">About Us</a></li>
      <li><a href="#services">Our Services</a></li>
      <li><a href="#faq">FAQ</a></li>
      <li><a href="#contact">Contact Us</a></li>
    </ul>
    <div class="auth-buttons">
      <a href="login.php" class="btn-ghost">Log In</a>
      <a href="register.php" class="btn-primary">Register</a>
    </div>
  </div>
</nav>

<!-- Hero -->
<section class="hero" id="home">
  <div class="hero-inner">
    <div class="hero-content">
      <p class="eyebrow">Alberba Dental Clinic · Est. 2017</p>
      <h1>Healthy smiles, <em>close to home.</em></h1>
      <p class="hero-sub">Family dentistry on Montalban Road — cleanings, orthodontics, extractions, and full smile restorations from a team that's been part of this community for almost a decade.</p>
      <div class="hero-actions">
        <a href="register.php" class="btn-primary">Book an Appointment</a>
        <a href="login.php" class="btn-ghost">Log In</a>
      </div>
      <ul class="trust-chips">
        <li>Licensed dentists</li>
        <li>Open 7 days a week</li>
        <li>Walk-ins welcome</li>
      </ul>
    </div>
    <div class="hero-visual">
      <div class="hero-blob" aria-hidden="true"></div>
      <img class="hero-photo" src="https://images.unsplash.com/photo-1588776814546-1ffcf47267a5?w=900&h=1100&fit=crop" alt="Dentist examining a patient's smile at Alberba Dental Clinic">
    </div>
  </div>
  <div class="scallop" aria-hidden="true">
    <svg viewBox="0 0 1200 40" preserveAspectRatio="none"><path d="M0,20 C50,0 100,0 150,20 C200,40 250,40 300,20 C350,0 400,0 450,20 C500,40 550,40 600,20 C650,0 700,0 750,20 C800,40 850,40 900,20 C950,0 1000,0 1050,20 C1100,40 1150,40 1200,20 L1200,40 L0,40 Z" fill="#fffbf9"/></svg>
  </div>
</section>

<!-- About -->
<section class="about" id="about">
  <div class="wrap">
    <div class="about-head">
      <p class="eyebrow" style="text-align:center;">About Us</p>
      <h2>Nine years of care on Montalban Road</h2>
    </div>
    <div class="about-grid">
      <blockquote class="about-quote">
        "We wanted a clinic where the whole family — grandparents to toddlers — could feel at ease in the chair."
        <cite>The Alberba Dental Team</cite>
      </blockquote>
      <div class="about-copy">
        <p>Alberba Dental Clinic opened its doors in 2017 with a simple goal: make quality dental care easy to reach for the families along Manila Hills Montalban Road. Almost a decade later, that's still the whole point.</p>
        <p>Our licensed dentists and staff work out of a modern, relaxed space built for patients of every age — from a child's first cleaning to a grandparent's new set of dentures. Whether you're here for a routine checkup, cosmetic work, orthodontics, or restorative treatment, care is planned around you, not the other way around.</p>
        <ul class="about-facts">
          <li><strong>2017</strong>Clinic founded</li>
          <li><strong>7</strong>Days open a week</li>
          <li><strong>4</strong>Core specialties</li>
        </ul>
      </div>
    </div>
  </div>
  <div class="scallop flip" aria-hidden="true">
    <svg viewBox="0 0 1200 40" preserveAspectRatio="none"><path d="M0,20 C50,0 100,0 150,20 C200,40 250,40 300,20 C350,0 400,0 450,20 C500,40 550,40 600,20 C650,0 700,0 750,20 C800,40 850,40 900,20 C950,0 1000,0 1050,20 C1100,40 1150,40 1200,20 L1200,40 L0,40 Z" fill="#f8e8f0"/></svg>
  </div>
</section>

<!-- Services -->
<section class="services" id="services">
  <div class="wrap">
    <div class="services-head">
      <p class="eyebrow" style="text-align:center;">Our Services</p>
      <h2>Care for every stage of your smile</h2>
    </div>
    <div class="services-grid">
      <div class="service-card">
        <img class="service-image" src="https://images.unsplash.com/photo-1606811841689-23dfddce3e95?w=600&h=400&fit=crop" alt="Orthodontic braces equipment">
        <div class="service-card-body">
          <h3>Orthodontics</h3>
          <p>Braces and aligners fitted and adjusted in-house, with a plan built around how your bite actually moves over time.</p>
        </div>
      </div>
      <div class="service-card">
        <img class="service-image" src="https://plus.unsplash.com/premium_photo-1673728789221-66eb5b1237f7?auto=format&fit=crop&q=80&w=1170" alt="Dental extraction tools">
        <div class="service-card-body">
          <h3>Extractions</h3>
          <p>Gentle, precise tooth removal for damaged or impacted teeth, with clear aftercare instructions before you leave the chair.</p>
        </div>
      </div>
      <div class="service-card">
        <img class="service-image" src="https://images.unsplash.com/photo-1631596630738-1cdd73a06164?auto=format&fit=crop&q=80&w=1170" alt="Dental implant model">
        <div class="service-card-body">
          <h3>Implants</h3>
          <p>Permanent tooth replacement that's built to last — restoring how you bite, speak, and smile without a second thought.</p>
        </div>
      </div>
      <div class="service-card">
        <img class="service-image" src="https://plus.unsplash.com/premium_photo-1702599007664-9de7ec7bc72e?auto=format&fit=crop&q=80&w=1332" alt="Dental crowns and bridges">
        <div class="service-card-body">
          <h3>Prosthodontics</h3>
          <p>Custom crowns, bridges, and dentures shaped and shaded to match your own teeth, not a generic mold.</p>
        </div>
      </div>
    </div>
  </div>
  <div class="scallop" aria-hidden="true">
    <svg viewBox="0 0 1200 40" preserveAspectRatio="none"><path d="M0,20 C50,0 100,0 150,20 C200,40 250,40 300,20 C350,0 400,0 450,20 C500,40 550,40 600,20 C650,0 700,0 750,20 C800,40 850,40 900,20 C950,0 1000,0 1050,20 C1100,40 1150,40 1200,20 L1200,40 L0,40 Z" fill="#fffbf9"/></svg>
  </div>
</section>

<!-- FAQ -->
<section class="faq" id="faq">
  <div class="faq-head">
    <p class="eyebrow" style="text-align:center;">FAQ</p>
    <h2>Frequently asked questions</h2>
  </div>

  <div class="faq-item">
    <div class="faq-question">
      What are dental temporary crowns and how long do they last?
      <span class="faq-toggle">+</span>
    </div>
    <div class="faq-answer">
      Temporary crowns are provisional restorations placed over a prepared tooth while your permanent crown is being made in the lab. They protect the tooth, hold its space, and keep things looking normal in the meantime. Expect them to last about 2–3 weeks — avoid sticky or hard foods, and keep the area clean until your permanent crown is ready.
    </div>
  </div>

  <div class="faq-item">
    <div class="faq-question">
      How often should I visit the dentist?
      <span class="faq-toggle">+</span>
    </div>
    <div class="faq-answer">
      Every six months for a routine checkup and cleaning is the general rule — it's enough to catch most problems early. If you have an ongoing dental concern, your dentist may suggest a shorter interval between visits.
    </div>
  </div>

  <div class="faq-item">
    <div class="faq-question">
      Do you accept walk-in patients?
      <span class="faq-toggle">+</span>
    </div>
    <div class="faq-answer">
      Yes, based on availability. Booking ahead through the site still gets you the shortest wait, and it guarantees we can fit your treatment into the schedule. Dental emergencies are always prioritized. Call 0939-118-0066 to check same-day availability.
    </div>
  </div>

  <div class="faq-item">
    <div class="faq-question">
      What payment methods do you accept?
      <span class="faq-toggle">+</span>
    </div>
    <div class="faq-answer">
      Cash, major credit and debit cards, and bank transfers are all accepted at the clinic. For larger treatment plans, ask our front desk about a flexible payment schedule.
    </div>
  </div>
</section>

<!-- Contact -->
<section class="contact" id="contact">
  <div class="contact-inner">
    <div>
      <p class="eyebrow" style="color:#f6c9db;">Get in Touch</p>
      <h2>Come say hello</h2>
      <p class="contact-lede">Have a question before you book, or need to reach the front desk directly? Here's every way to find us.</p>
      <ul class="contact-list">
        <li><span class="ico">📍</span> Manila Hills, Montalban Road</li>
        <li><span class="ico">📞</span> 0926-711-6060</li>
        <li><span class="ico">✉️</span> alberbadentalclinic@gmail.com</li>
        <li><span class="ico">🕐</span> Mon–Sun, 7:30 AM – 5:00 PM</li>
      </ul>
    </div>
    <div class="contact-card">
      <h3>Ready when you are</h3>
      <p>Create an account to book a visit, track appointments, and see your treatment history in one place.</p>
      <a href="register.php" class="btn-light">Create an Account</a>
    </div>
  </div>
</section>

<!-- Footer -->
<footer>
  <p><strong>Alberba Dental Clinic</strong> &nbsp;·&nbsp; Family dentistry on Montalban Road since 2017</p>
  <p style="margin-top:.4rem;opacity:.75;">&copy; 2026 Alberba Dental Clinic. All rights reserved.</p>
</footer>

<script>
  // FAQ accordion — one open at a time
  document.querySelectorAll('.faq-question').forEach(question => {
    question.addEventListener('click', function () {
      const item = this.parentElement;
      const isActive = item.classList.contains('active');
      document.querySelectorAll('.faq-item').forEach(i => i.classList.remove('active'));
      if (!isActive) item.classList.add('active');
    });
  });
</script>
</body>
</html>
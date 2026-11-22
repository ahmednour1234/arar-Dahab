<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#0f0f10">
  <meta name="robots" content="noindex, nofollow">
  <title>شرح الاستخدام — ماسح الذهب</title>
  <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='%23d4af37' d='M6 3h12l4 6-10 12L2 9z'/%3E%3C/svg%3E">
  <link rel="stylesheet" href="{{ asset('static/styles.css') }}?v={{ filemtime(public_path('static/styles.css')) }}">
  <style>
    .help main { max-width: 760px; }
    .help h2 { font-size: 1.3rem; margin: 0 0 10px; color: var(--gold2); display: flex; align-items: center; gap: 10px; }
    .help h2 .n { flex: none; width: 30px; height: 30px; border-radius: 50%; display: grid; place-items: center; font-size: .95rem; background: rgba(212,175,55,.15); border: 1px solid var(--gold-dim); color: var(--gold2); }
    .help h3 { font-size: 1.02rem; margin: 16px 0 6px; }
    .help section.card { margin-bottom: 14px; line-height: 1.85; }
    .help p { margin: 6px 0; }
    .help ol, .help ul { margin: 6px 0; padding-inline-start: 22px; }
    .help li { margin: 4px 0; }
    .help code, .help kbd { font-family: Consolas, "Roboto Mono", monospace; background: var(--bg2); border: 1px solid var(--line); border-radius: 6px; padding: 1px 6px; direction: ltr; unicode-bidi: embed; color: var(--gold2); font-size: .9em; }
    .help pre { background: var(--bg2); border: 1px solid var(--line); border-radius: 10px; padding: 10px 12px; overflow-x: auto; direction: ltr; text-align: left; font-size: .85rem; color: var(--gold2); margin: 8px 0; }
    .help table { width: 100%; border-collapse: collapse; margin: 8px 0; font-size: .92rem; }
    .help th, .help td { border: 1px solid var(--line); padding: 8px 10px; text-align: right; vertical-align: top; }
    .help th { background: var(--bg2); color: var(--muted); font-weight: 600; }
    .help .tip { background: rgba(212,175,55,.08); border: 1px solid rgba(212,175,55,.3); border-radius: 10px; padding: 10px 12px; margin: 10px 0; font-size: .93rem; }
    .help .warn { background: rgba(239,83,80,.08); border: 1px solid rgba(239,83,80,.35); border-radius: 10px; padding: 10px 12px; margin: 10px 0; font-size: .93rem; color: #ffcfcd; }
    .toc { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 14px; }
    .toc a { color: var(--text); text-decoration: none; background: var(--card); border: 1px solid var(--line); border-radius: 999px; padding: 6px 12px; font-size: .88rem; }
    .toc a:hover { border-color: var(--gold-dim); color: var(--gold2); }
    .help a.inline { color: var(--gold2); }
    .icon-inline { display: inline-grid; place-items: center; width: 26px; height: 26px; border: 1px solid var(--line); border-radius: 7px; vertical-align: middle; color: var(--gold); margin: 0 2px; }
    .icon-inline svg { width: 16px; height: 16px; }
    .table-wrap { overflow-x: auto; }
    .help .topbar .btn { white-space: nowrap; flex: none; }
    @media (max-width: 400px) { .help .topbar .sync { display: none; } }
  </style>
</head>
<body class="help">
  <header class="topbar">
    <div class="brand">
      <svg class="logo" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h12l4 6-10 12L2 9z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M2 9h20M9 3l3 18 3-18M6 3l3 6m9-6-3 6" fill="none" stroke="currentColor" stroke-width="1.1" opacity=".7"/></svg>
      <div>
        <h1>شرح الاستخدام</h1>
        <div class="sync">ماسح الذهب — كل حاجة خطوة بخطوة</div>
      </div>
    </div>
    <a class="btn gold small-btn" href="{{ url('/') }}" style="text-decoration:none">رجوع للماسح</a>
  </header>

  <main>
    <nav class="toc" aria-label="المحتويات">
      <a href="#start">البداية</a>
      <a href="#scan">المسح والبحث</a>
      <a href="#honeywell">جهاز Honeywell</a>
      <a href="#camera">كاميرا الجوال</a>
      <a href="#list">كل الباركودات والنسخ</a>
      <a href="#upload">رفع ملفات Excel</a>
      <a href="#sync">المزامنة من جهازك</a>
      <a href="#excel">تجهيز ملف Excel</a>
      <a href="#status">رسائل الحالة</a>
      <a href="#trouble">حل المشاكل</a>
    </nav>

    <section class="card" id="start">
      <h2><span class="n">1</span>البداية</h2>
      <ol>
        <li>افتح الموقع من أي متصفح (Chrome على الجوال أو الماسح، أو أي متصفح على الكمبيوتر).</li>
        <li>أول مرة هيطلب منك <b>مفتاح الدخول</b>. المفتاح ده بيديهولك مسؤول النظام، واكتبه مرة واحدة بس، والجهاز بيحفظه.</li>
        <li>ممكن تفتح رابط فيه المفتاح عشان ما تكتبوش بإيدك: <code>{{ url('/') }}/?key=المفتاح</code></li>
        <li>على الجوال أو الماسح: من قائمة Chrome <b>(⋮)</b> اختار <b>Add to Home screen</b>، والموقع هيبقى أيقونة زي التطبيق.</li>
      </ol>
      <h3>أزرار أعلى الشاشة</h3>
      <div class="table-wrap"><table>
        <tr><th>الزر</th><th>وظيفته</th></tr>
        <tr><td><span class="icon-inline"><svg viewBox="0 0 24 24"><path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></span></td><td>عرض كل الباركودات من كل الملفات، مع البحث والنسخ</td></tr>
        <tr><td><span class="icon-inline"><svg viewBox="0 0 24 24"><path d="M12 16V4m0 0-5 5m5-5 5 5M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span></td><td>رفع ملفات Excel وإدارتها</td></tr>
        <tr><td><span class="icon-inline">؟</span></td><td>صفحة الشرح دي</td></tr>
        <tr><td>● متصل</td><td>حالة الاتصال: <span style="color:var(--ok)">أخضر</span> = تمام، <span style="color:var(--warn)">برتقالي</span> = البيانات ممكن تكون قديمة، <span style="color:var(--err)">أحمر</span> = مفيش اتصال</td></tr>
      </table></div>
    </section>

    <section class="card" id="scan">
      <h2><span class="n">2</span>المسح والبحث</h2>
      <ol>
        <li>خانة الباركود بتاخد التركيز لوحدها. امسح الباركود بالماسح، أو اكتبه واضغط <b>بحث</b> أو <kbd>Enter</kbd>.</li>
        <li>تفاصيل القطعة بتظهر: الاسم، العيار، الوزن، السعر المخزّن، الحالة، الفرع، الملاحظات، واسم الملف ورقم الصف.</li>
        <li>عشان تمسح القطعة اللي بعدها، امسح على طول من شاشة النتيجة، أو اضغط <b>مسح قطعة جديدة</b>.</li>
      </ol>
      <h3>فلتر "البحث في"</h3>
      <p>لو فيه أكتر من ملف Excel، بيظهر فوق خانة الباركود. اختار <b>كل الملفات</b> أو ملف معين، والبحث هيبقى فيه بس.</p>
      <h3>أيقونات جوه خانة الباركود</h3>
      <ul>
        <li><b>📋 لصق:</b> بيلصق الباركود اللي نسخته ويبحث عنه على طول. ولو استخدمت <kbd>Ctrl</kbd>+<kbd>V</kbd> جوه الخانة، البحث بيبدأ لوحده برضه.</li>
        <li><b>⌨ كيبورد:</b> بيظهر كيبورد الجوال عشان تكتب الباركود بإيدك. الكيبورد بيكون مخفي في الأول عشان ما يغطيش الشاشة وانت بتستخدم الماسح.</li>
      </ul>
      <h3>النتايج الممكنة</h3>
      <div class="table-wrap"><table>
        <tr><th>اللي بيظهر</th><th>معناه</th></tr>
        <tr><td>تفاصيل القطعة</td><td>الباركود موجود مرة واحدة بس، والتفاصيل دي هي تفاصيله.</td></tr>
        <tr><td>القطعة غير موجودة في ملف المخزون</td><td>الباركود مش موجود في أي ملف (أو في الملف اللي اخترته في الفلتر).</td></tr>
        <tr><td>خطأ: باركود مكرر</td><td>نفس الباركود متسجل أكتر من مرة، والبرنامج مش هيختار واحدة منهم عشوائي. بيعرضلك اسم الملف ورقم الصف لكل نسخة، عشان تصلّحها في Excel. وممكن تختار ملف واحد من الفلتر.</td></tr>
      </table></div>
      <h3>نسخ من شاشة النتيجة</h3>
      <p><b>نسخ الباركود</b> بينسخ الرقم بس، و<b>نسخ التفاصيل</b> بينسخ كل بيانات القطعة كنص تقدر تبعته واتساب أو تلصقه في أي مكان.</p>
    </section>

    <section class="card" id="honeywell">
      <h2><span class="n">3</span>توصيل جهاز Honeywell</h2>
      <p>جهاز Honeywell بيكتب الباركود في الخانة كأنه كيبورد، وبيضغط Enter لوحده. يعني مش محتاج تسطّب أي تطبيق.</p>
      <ol>
        <li>وصّل الجهاز بالإنترنت، سواء Wi‑Fi أو شريحة بيانات.</li>
        <li>افتح <b>Chrome</b> وادخل على: <code>{{ url('/') }}/?key=مفتاح_الدخول</code></li>
        <li>اضبط إعدادات الماسح من: <b>Settings ← Honeywell Settings ← Scanning ← Internal Scanner ← Default profile</b></li>
      </ol>
      <div class="table-wrap"><table>
        <tr><th>الإعداد</th><th>القيمة</th></tr>
        <tr><td>Data Processing Settings → Wedge</td><td>On (مفعّل)</td></tr>
        <tr><td>Wedge Method</td><td>Keyboard أو Standard</td></tr>
        <tr><td>Suffix</td><td><code>\n</code> (يعني Enter)</td></tr>
        <tr><td>Scan to Intent</td><td>Off (مقفول)</td></tr>
      </table></div>
      <div class="tip">أسماء القوايم ممكن تختلف شوية حسب الموديل (CT40 / CT60 / EDA51 / EDA52) وإصدار أندرويد. لو الماسح بيكتب الرقم من غير ما يبدأ البحث، يبقى الـ Suffix مش Enter.</div>
    </section>

    <section class="card" id="camera">
      <h2><span class="n">4</span>المسح بكاميرا الجوال</h2>
      <ol>
        <li>اضغط <b>مسح بكاميرا الجوال</b> واسمح للمتصفح يستخدم الكاميرا.</li>
        <li>وجّه الكاميرا على الباركود جوه الإطار الدهبي، والبحث هيبدأ لوحده.</li>
      </ol>
      <p>بيقرا Code128 وEAN-13 وEAN-8 وUPC وCode39 وQR وغيرهم.</p>
      <div class="tip">الكاميرا بتشتغل بس لما الموقع يتفتح بـ <code>https://</code>. لو المتصفح رفض الإذن، افتح إعدادات الموقع في Chrome (القفل اللي جنب الرابط) واسمح بالكاميرا.</div>
    </section>

    <section class="card" id="list">
      <h2><span class="n">5</span>كل الباركودات والنسخ</h2>
      <ol>
        <li>اضغط زر القائمة ☰ في أعلى الشاشة.</li>
        <li>هتظهرلك كل القطع من كل الملفات. ابحث بالباركود أو الاسم أو الفئة أو اسم الملف، أو اختار ملف معين من الفلتر.</li>
        <li>لو ضغطت على أي قطعة، تفاصيلها هتفتح.</li>
      </ol>
      <div class="table-wrap"><table>
        <tr><th>الزر</th><th>بيعمل إيه</th></tr>
        <tr><td>أيقونة النسخ جنب كل قطعة</td><td>بتنسخ الباركود بتاع القطعة دي بس</td></tr>
        <tr><td>نسخ الباركودات</td><td>بتنسخ كل الباركودات اللي ظاهرة (حسب البحث والفلتر)، كل باركود في سطر</td></tr>
        <tr><td>نسخ الجدول (للصق في Excel)</td><td>بتنسخ جدول فيه الباركود والاسم والفئة والعيار والوزن والسعر والحالة والملف. افتح Excel واضغط <kbd>Ctrl</kbd>+<kbd>V</kbd>، وكل قيمة هتنزل في عمودها.</td></tr>
      </table></div>
      <p>القطع اللي باركودها مكرر بيظهر جنبها علامة <span class="badge err">مكرر</span>.</p>
    </section>

    <section class="card" id="upload">
      <h2><span class="n">6</span>رفع ملفات Excel</h2>
      <ol>
        <li>اضغط زر الرفع ⬆ في أعلى الشاشة.</li>
        <li>اختار ملف بصيغة <code>.xls</code> أو <code>.xlsx</code> أو <code>.csv</code>.</li>
        <li>لو ظهرت خانة <b>مفتاح المسؤول</b>، اكتبه.</li>
        <li>اضغط <b>رفع وتحديث البيانات</b>. البيانات بتتحدث على طول على كل الأجهزة.</li>
      </ol>
      <ul>
        <li><b>ملف باسم جديد</b> بيتضاف جنب الملفات الموجودة، والبحث بيشمل كل الملفات.</li>
        <li><b>ملف بنفس اسم ملف موجود</b> بيحل محل القديم، والقديم بيتحفظ كنسخة احتياطية على السيرفر.</li>
        <li>تحت في صفحة الرفع فيه قائمة <b>الملفات المرفوعة</b>: عدد القطع في كل ملف، وزر <b>حذف</b>. الملف المحذوف بيتحفظ كنسخة احتياطية.</li>
      </ul>
      <div class="tip">الملف بيتفحص قبل ما يتحفظ. لو مفيش عمود باركود، أو الملف فاضي أو تالف، بيترفض والبيانات الحالية بتفضل زي ما هي.</div>
    </section>

    <section class="card" id="sync">
      <h2><span class="n">7</span>المزامنة التلقائية من جهازك (من غير رفع)</h2>
      <p>السيرفر ما يقدرش يفتح ملفات على كمبيوترك مباشرة. عشان كده فيه برنامج صغير بيشتغل على كمبيوتر المحل، بيراقب ملفات الإكسل، وكل ما تحفظ ملف بيرفعه للسيرفر لوحده. البرنامج موجود في فولدر <code>sync-agent</code> في المشروع، وبيشتغل على ويندوز من غير ما تسطّب حاجة.</p>
      <ol>
        <li>انسخ فولدر <code>sync-agent</code> على كمبيوتر المحل.</li>
        <li>شغّل <code>start-sync.bat</code>. أول مرة هيفتحلك ملف الإعدادات <code>gold-sync.config.json</code>.</li>
        <li>اكتب فيه رابط الموقع ومفتاح الدخول (ومفتاح المسؤول لو مستخدم)، ومسارات ملفات الإكسل أو الفولدر اللي فيه الملفات:</li>
      </ol>
<pre>{
  "server_url": "{{ url('/') }}",
  "access_key": "مفتاح الدخول",
  "admin_key": "",
  "paths": ["C:\\المخزون", "D:\\ملفات\\فرع2.xlsx"],
  "poll_seconds": 5
}</pre>
      <ol start="4">
        <li>شغّل <code>start-sync.bat</code> تاني، وسيب الشباك مفتوح. أي ملف تحفظه في Excel بيترفع خلال ثواني.</li>
        <li>عشان يشتغل لوحده مع كل تشغيل للويندوز، شغّل <code>install-autostart.bat</code>.</li>
      </ol>
      <p>كل حاجة البرنامج بيعملها بتتسجل في ملف <code>gold-sync.log</code> جنبه.</p>
    </section>

    <section class="card" id="excel">
      <h2><span class="n">8</span>تجهيز ملف Excel</h2>
      <ul>
        <li>أول صف لازم يكون عناوين الأعمدة.</li>
        <li><b>عمود الباركود هو الوحيد المطلوب.</b> اسمه ممكن يكون: <code>Barcode</code> أو <code>الباركود</code> أو <code>باركود</code>.</li>
        <li>الأعمدة الاختيارية اللي البرنامج بيعرفها:</li>
      </ul>
      <div class="table-wrap"><table>
        <tr><th>البيان</th><th>أسماء العمود المقبولة</th></tr>
        <tr><td>اسم القطعة</td><td>Item Name / اسم القطعة / الصنف</td></tr>
        <tr><td>الفئة</td><td>Category / الفئة / التصنيف</td></tr>
        <tr><td>العيار</td><td>Karat / العيار</td></tr>
        <tr><td>الوزن</td><td>Weight / الوزن</td></tr>
        <tr><td>المصنعية</td><td>Making Charge / المصنعية</td></tr>
        <tr><td>السعر</td><td>Price / السعر</td></tr>
        <tr><td>الحالة</td><td>Status / الحالة</td></tr>
        <tr><td>الفرع</td><td>Branch / الفرع</td></tr>
        <tr><td>ملاحظات</td><td>Notes / ملاحظات</td></tr>
      </table></div>
      <ul>
        <li>أي عمود تاني، زي رقم المورد، بيظهر برضه في تفاصيل القطعة.</li>
        <li><b>الباركود اللي بيبدأ بأصفار</b> (زي <code>00123</code>): خلي خلايا العمود ده بتنسيق <b>نص (Text)</b> في Excel، لأن Excel نفسه بيمسح الأصفار من الأرقام.</li>
        <li>الأسعار بتظهر زي ما هي متسجلة في الملف بالظبط. البرنامج مش بيحسب أي حاجة من سعر الدهب.</li>
        <li>ملفات CSV: البرنامج بيقرا الترميز UTF-8 وكمان Windows-1256 العربي، والفاصل بين الأعمدة (<code>,</code> أو <code>;</code>) بيتعرف لوحده.</li>
      </ul>
    </section>

    <section class="card" id="status">
      <h2><span class="n">9</span>رسائل الحالة</h2>
      <div class="table-wrap"><table>
        <tr><th>الرسالة</th><th>معناها</th></tr>
        <tr><td>آخر تحديث ناجح: …</td><td>آخر مرة البيانات اتحمّلت بنجاح من ملف Excel.</td></tr>
        <tr><td>تنبيه: يوجد X باركود مكرر</td><td>فيه باركودات متسجلة أكتر من مرة. افتح صفحة كل الباركودات عشان تشوف أماكنها.</td></tr>
        <tr><td>بيانات قديمة (برتقالي)</td><td>آخر ملف اترفع أو اتعدّل ما اتقراش صح، والبرنامج بيعرض آخر بيانات سليمة. شوف الملف في صفحة الرفع.</td></tr>
        <tr><td>غير متصل (أحمر)</td><td>الجهاز مش قادر يوصل للسيرفر. اتأكد من الإنترنت.</td></tr>
      </table></div>
    </section>

    <section class="card" id="trouble">
      <h2><span class="n">10</span>حل المشاكل</h2>
      <div class="table-wrap"><table>
        <tr><th>المشكلة</th><th>الحل</th></tr>
        <tr><td>الماسح بيكتب الرقم بس البحث مش بيبدأ</td><td>اضبط الـ Suffix في إعدادات الماسح على Enter.</td></tr>
        <tr><td>الماسح مش بيكتب حاجة خالص</td><td>فعّل الـ Wedge واقفل Scan to Intent. واتأكد إن صفحة الماسح هي اللي مفتوحة قدامك.</td></tr>
        <tr><td>"مفتاح الدخول غير صحيح"</td><td>اطلب المفتاح الصح من المسؤول، وافتح الرابط اللي فيه <code>?key=</code> تاني.</td></tr>
        <tr><td>"مفتاح المسؤول غير صحيح"</td><td>المفتاح لازم يكون نفس قيمة <code>GS_ADMIN_KEY</code> اللي في ملف الإعدادات على السيرفر.</td></tr>
        <tr><td>الكاميرا مش بتفتح</td><td>لازم الموقع يتفتح بـ <code>https</code>، ولازم تسمح للمتصفح باستخدام الكاميرا.</td></tr>
        <tr><td>الملف اترفض</td><td>اقرا سبب الرفض في الرسالة الحمرا. غالباً يكون عمود الباركود مش موجود، أو الملف فاضي، أو صيغته مش مدعومة.</td></tr>
        <tr><td>الصفحة شكلها قديم بعد التحديث</td><td>اعمل تحديث للصفحة بـ <kbd>Ctrl</kbd>+<kbd>F5</kbd>.</td></tr>
      </table></div>
      <div class="warn">ما تشاركش مفتاح الدخول أو مفتاح المسؤول مع حد من بره المحل. أي حد معاه المفتاح يقدر يشوف بيانات المخزون والأسعار.</div>
    </section>

    <a class="btn gold big" href="{{ url('/') }}" style="text-decoration:none">رجوع للماسح</a>
  </main>
  <footer class="foot">ماسح الذهب</footer>
</body>
</html>

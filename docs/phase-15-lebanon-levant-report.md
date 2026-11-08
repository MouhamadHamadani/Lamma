# Phase 15 report: Lebanon & Levant and about 720 more questions

The question bank went from 1,258 playable questions to **1,979**, and gained a subcategory with a Lebanese and Levantine slant. Content, a few config numbers, one new artisan command and two small UI fixes; no migration, no new screen, no new component.

Read these next to this report: [content-review-phase-15.md](content-review-phase-15.md) (every new question, for your spot-check), CLAUDE.md "Phase 15", [manual-test-plan.md](manual-test-plan.md) section 25, HANDOFF section 15.

## 1. What was built, per section

**Section 1: Lebanon & Levant.** New subcategory `lebanon-levant` / «لبنان وبلاد الشام» under The Arab World, icon `leaf` (from the existing set: it reads as a cedar leaf), file `data/questions/lebanon-levant.php` with **120 questions: 30 easy, 45 medium, 45 hard**. Food and drink (manousheh, kaak, maamoul, sumac, sfouf, jallab, sahlab, mouneh, white coffee, shanklish, kishk, hindbeh, kibbeh nayyeh, muhammara, shish barak, kebab karaz), places and nature (Qurnat as Sawda, the Shouf and Qadisha, Anjar, Baalbek, Byblos, Tyre, Sidon, Tripoli, Zahle, Jezzine, Batroun, the Litani and Nahr el-Kalb, Jeita, Raouché, Bosra, Ugarit, Hama, Maaloula), language (18 dialect questions), music, cinema and letters (Fairuz, the Rahbanis, buzuq, mijwiz, zajal, Capernaum, Caramel, Gibran, Maalouf, the Pen League, Bustani), crafts and customs (the hakawati, the zaffe, the tantour, mulberry and silk) and Lebanese people by what they achieved (Choucair, Elie Saab, Gabriel Yared, Rony Seikaly, Michael DeBakey). No parties, leaders, wars or sectarian topics; no alcohol (arak and wine are left out on purpose).
- *Dialect exception:* the Arabic of the dialect questions is Lebanese on purpose, with the meaning in the English text. The header of the file says so. The task said to "allow it in ContentTest's stale-word/MSA lint", but there was no MSA lint, so I **added one** (a list of whole dialect words such as كتير, هيك, شو, هلق, ليش, وين): it fails if one appears in any other file, and requires the Lebanon file to use them.
- *Translation of the subcategory fixed in both locales:* `ar` «لبنان وبلاد الشام», `en` "Lebanon & Levant", shown as «العالم العربي · لبنان وبلاد الشام» / "The Arab World · Lebanon & Levant" on the question chips.

**Section 2: six files raised to 40 / 40 / 40.** Football (+72), basketball (+72), words-language (+72), arts-music (+72), everyday-life (+67), food-drink (+35). Food & Drink needed only 35 because it already had 85, not the 72 the prompt estimated. The regional angle: Arab and African teams at the World Cup, the Asian and African cups, local derbies and nicknames (football); Lebanese and Arab basketball, Salah Mejri, Afrobasket 2011 (basketball); Arabic letters, grammar, poetry and dictionaries, Arabic words in English and Spanish, the Levantine month names (words); maqam, tarab, Arab cinema, the Nights, Tayeb Salih, Mahfouz's trilogy, Islamic art (arts); dialling codes, `.lb`/`.ae`/`.jo`, dallah, majlis, thobe, hammam (everyday life); koshari, tagine, qamar al-Din, luqaimat, Aleppo pistachio, qatr (food).

**Section 3: the hard-tier pass.** Every subcategory now has at least 20 hard questions (4 more in each of the 8 that had 16), and every top-level category at least 28 of its own: General Knowledge and Sports went from 10 to 28 through two new files (`data/questions/general-knowledge.php` and `sports.php`, 18 each). Several are Arab: Ons Jabeur, Nawal El Moutawakel, Hicham El Guerrouj, the Bahrain and Abu Dhabi Grands Prix, Abu Simbel and the Aswan High Dam, Fatima al-Fihri.

**Section 4: config and tooling.**
- `config/lamma.php` `content`: subcategory 60 / 15 each / **20 hard**, category 100 / 25 / **28 hard**, plus a new `parent` key (**28 hard** of its own for a category that has subcategories) and `warn_margin` 3. The `hard` floors are how item 3 is enforced: `QuestionReport` and `lamma:doctor` both check them, and the doctor fails under a floor with no warning margin.
- `lamma:questions-report` now ends with a second table, per top-level category with its subcategories added in, and shows the hard floors.
- **`lamma:questions-audit`** (`App\Game\QuestionAudit`): per slug and difficulty counts; the share of questions whose correct option is strictly the longest (English and Arabic); numeric answers; an answer repeated more than 3 times in one slug; different questions with the very same three wrong options; time-sensitive wording (current, latest, today, now, reigning, a year from 2023). `--file=` audits a fixture; `--strict` exits 1 on a defect.
- *Stayed green:* to keep `lamma:doctor` green the thin files had to clear each target by the 3-question warning margin, which is why they hold 63 and 103 questions rather than exactly 60 and 100.

**Section 5: review aid.** [content-review-phase-15.md](content-review-phase-15.md): all 721 new questions in a table per file (the Lebanon table also shows the Arabic), the dialect rows marked, and a list at the top of what most needs a native-Arabic and a Lebanese read.

**Deviations from the prompt, and why**
1. **721 new questions, not "about 550".** The raised targets are not reachable with the prompt's own numbers: eight subcategories sit at 52 after the hard-tier pass (the target is 60) and four categories at 85–92 (the target is 100), and the doctor warns within 3 of a target. Filling them took 107 questions, and the margin 36 more. The alternative (lowering the totals) would have contradicted the numbers you gave, so I wrote the content.
2. **Two real UI bugs fixed**, because the new data exposed them:
   - A parent with one subcategory collapsed to "all of it" when its chip was chosen, so a host could never pick *only* Lebanon & Levant. Now the lone chip means "only this".
   - The landing page took any six categories by sort order, so Football or Lebanon & Levant (each sort order 1 in its parent) could crowd Science and Geography out of the examples. It now takes the first six top-level categories.
3. **`lamma:questions-audit` exits 0 by default** (and 1 with `--strict`). The shipped bank still has a few flags I cannot fix: a question that is already live cannot be reworded (its English text is its identity, so a new wording would be added as a *second* question). The remaining defects are all pre-Phase-15 questions (two say "today", about twenty pairs of old questions share wrong options such as colours and countries); the new content has none.
4. **A bank-wide near-duplicate scan** (same correct answer plus overlapping wording) found about 25 new questions that were close copies of ones in other categories (Starry Night, the Louvre, the Sistine ceiling, five players on a court, the rim's height, 90 minutes, Spain 2010, the 1930 World Cup, the tagine, body temperature, Mandarin, the Alhambra, Owens, Kano...). They were replaced with new facts, so none of the 721 repeats a live question. The scan is not a command; see CLAUDE.md "Writing new questions".
5. I ran `ContentSeeder` on the local MySQL database (additive, safe) to check the report and the doctor against real rows.

## 2. Test results

TEST_RESULTS_PLACEHOLDER

## 3. Arabic strings that need a native review

The full list is [content-review-phase-15.md](content-review-phase-15.md) (721 questions; the Arabic of the Lebanon file is printed in it). The ones that matter most:

- **The 18 dialect questions** (Lebanese wording on purpose): «صحتين», «كيفك؟», «هلّأ», «شو», «كتير», «يلّا», «بكرا», «بندورة», «بوسطة», «المونة», «كرمال», «شو في ما في؟», «قدّيش», «لسّا», «يعطيك العافية», the pronunciation of ق, «ضيعة», «دغري». Is each word spelled the way a Lebanese person would write it, and is each gloss what they would say it means?
- **The dialect sayings and nicknames:** «جارة القمر» (Fairuz), «أسود الأطلس», «ثعالب الصحراء», «نسور قرطاج», «الصقور الخضر», «الأرز» (Lebanon's football team), «الفيجيين الطائرين».
- **Lebanese food names:** المنقوشة, الكعك, المعمول, الشنكليش, السفوف, زنود الست, ليالي لبنان, الجلّاب, السحلب, المونة, الهندباء, الكشك, الكبّة النيّة, المحمّرة, «القهوة البيضاء».
- **Place names:** القرنة السوداء, بشرّي, البردوني, نهر الكلب, جزّين, البترون, عنجر, الشوف, القاديشا, بصرى, أوغاريت, حماة.
- **Levantine month names** in `words-language.php`: حزيران, تموز, أيلول, نيسان, آذار, أيّار, كانون الأول والثاني, تشرين الأول.
- **Transliterations of foreign names** in every file; the ones I am least sure of are Greek, Hungarian, Dutch and Japanese ones (e.g. «ليفنهوك», «يورن أوتزون», «موريهي أويشيبا», «غيتشين فوناكوشي», «بيرو»).
- **Terms I chose in Arabic that may have a more common form:** «ستالميت» (stalemate), «التبييت» (castling), «السكروم» / «اللاين أوت» / «الرك» / «المول» (rugby), «السويش» (basketball), «الهاتريك».

## 4. What needs you

**Deploy.** No migration, no new env value, no queue or Reverb restart. `deploy/deploy.sh` already runs the content seeder; on the next deploy it adds the **721 new questions and the Lebanon & Levant category** in one transaction (about 5 seconds on a laptop) and never touches what exists. `config/lamma.php` changed, so the deploy's `optimize` (config cache) must run, and it does. After the deploy:

```bash
php artisan lamma:questions-report    # "Every category meets its target.", 1,979 questions
php artisan lamma:doctor              # a PASS on Questions
php artisan lamma:questions-audit     # read the warnings; defects are all old questions
```

**Spot-check before deploying.** Open [content-review-phase-15.md](content-review-phase-15.md). The Lebanon table has the Arabic and an English gloss for each row, and the dialect rows are marked. Anything wrong is a one-line edit in the data file *before* deploy; after deploy, fix it in `/admin` (a live question's English text must not change in the file, or the seeder adds a second copy).

**Manual phone test.** [manual-test-plan.md](manual-test-plan.md) section 25: the chip in both languages, a Classic game on Lebanon & Levant only, a Vote game, the seeder run twice, the landing page. About 15 minutes with one laptop and one phone.

## 5. Things that could be annoying in real play

1. **Dialect questions in a mixed room.** A Syrian, Egyptian or Gulf player reads «شو في ما في» or «ضيعة» as a trick, and an English-only player sees a gloss as the option. *Suggestion:* tag dialect questions in the admin (a flag, not a category) so a host can leave them out, or a "Lebanese dialect" switch on Create room.
2. **The Arab World has one chip.** Tapping it means "only Lebanon & Levant", and with the whole category chosen, tapping it again switches everything off, which looks like a bug. *Suggestion:* give The Arab World a second subcategory (Gulf, North Africa) so it behaves like the others, or hide the chips of a parent with a single child and offer a toggle for "include Lebanon & Levant".
3. **Small subcategories repeat sooner.** Rugby, tennis, motorsport, olympics, combat sports, inventions, the human body and landmarks hold 63 questions each; a group playing 15-question games on one of them sees repeats from the second or third session (the warning on Create room appears). *Suggestion:* another 40 questions each next phase, starting with the ones people pick most (the admin's "most played" widget).

## 6. Not done / not verified

- The **browser tests** and the **production build** are covered in section 2; anything marked "not run" there was not run.
- Nothing was tested on a phone or against the live domain; that is the manual plan above.

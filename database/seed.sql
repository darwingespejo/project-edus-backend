-- EduSphere demo seed. All people and submissions are fictional placeholder data.
-- Every account's password: password123 (bcrypt hash below). Import after schema.sql.
-- Run: mysql --ssl-mode=REQUIRED -h $DB_HOST edusphere < seed.sql

SET NAMES utf8mb4;
SET @hash := '$2b$10$8VgbSQKl5y0eNaY3.Qs1Fe9vEz6QmyevRKzzfupY9XkM/YpSkRQUe';

INSERT INTO institutions (id, name, domain) VALUES ('inst-1', 'Demo Institute', 'edusphere.dev');

INSERT INTO users (id, name, email, password_hash, role, institution_id, xp, level) VALUES
 ('u-admin',  'Rae Okafor',  'admin@edusphere.dev',      @hash, 'admin',      'inst-1', 0, 1),
 ('u-priya',  'Priya Nair',  'instructor@edusphere.dev', @hash, 'instructor', 'inst-1', 0, 1),
 ('u-marco',  'Marco Reyes', 'marco@edusphere.dev',      @hash, 'instructor', 'inst-1', 0, 1),
 ('u-aria',   'Aria Chen',   'aria@edusphere.dev',       @hash, 'student',    'inst-1', 460, 3),
 ('u-theo',   'Theo Marsh',  'theo@edusphere.dev',       @hash, 'student',    'inst-1', 690, 3),
 ('u-noor',   'Noor Haddad', 'noor@edusphere.dev',       @hash, 'student',    'inst-1', 540, 3),
 ('u-felix',  'Felix Ortiz', 'felix@edusphere.dev',      @hash, 'student',    'inst-1', 320, 2),
 ('u-june',   'June Park',   'june@edusphere.dev',       @hash, 'student',    'inst-1', 210, 2),
 ('u-soleil', 'Soleil Marin','soleil@edusphere.dev',     @hash, 'student',    'inst-1', 780, 3),
 ('u-dev',    'Dev Patel',   'dev@edusphere.dev',        @hash, 'student',    'inst-1', 150, 2),
 ('u-maya',   'Maya Lind',   'maya@edusphere.dev',       @hash, 'student',    'inst-1', 410, 3),
 ('u-kir',    'Kir Volkov',  'kir@edusphere.dev',        @hash, 'student',    'inst-1', 60, 1),
 ('u-owen',   'Owen Cole',   'owen@edusphere.dev',       @hash, 'student',    'inst-1', 260, 2);

INSERT INTO skills (id, name, slug) VALUES
 ('sk-jb','JavaScript Basics','js-basics'), ('sk-cf','Control Flow','control-flow'),
 ('sk-fn','Functions','functions'), ('sk-ar','Arrays & Iteration','arrays'),
 ('sk-ob','Objects & Data','objects'), ('sk-dom','DOM & Events','dom'),
 ('sk-asy','Async & Promises','async'), ('sk-hc','HTML & CSS','html-css'),
 ('sk-pd','Problem Decomposition','decomposition'), ('sk-td','Testing & Debugging','testing');

INSERT INTO courses (id, instructor_id, title, slug, description, level, accent) VALUES
 ('c-jsf',  'u-priya', 'JavaScript Foundations', 'js-foundations', 'Core language skills: syntax, functions, data, and the event loop.', 'beginner', 'indigo'),
 ('c-fend', 'u-priya', 'Frontend Engineering',  'frontend-eng',  'Component thinking, DOM rendering, state, and interface polish.', 'intermediate', 'mint'),
 ('c-algo', 'u-marco', 'Algorithms Practice',   'algorithms',     'Problem decomposition, complexity awareness, and test-first habits.', 'advanced', 'coral');

INSERT INTO modules (id, course_id, title, position) VALUES
 ('m1','c-jsf','Core syntax',0), ('m2','c-jsf','Data & functions',1), ('m3','c-jsf','The browser',2),
 ('m4','c-fend','UI systems',0), ('m5','c-fend','State & data',1),
 ('m6','c-algo','Warm-up',0), ('m7','c-algo','Structures',1);

-- Content abbreviated here; full prose lives in the app repo's src/db/seed.ts.
INSERT INTO lessons (id, module_id, title, content, estimated_minutes, position) VALUES
 ('l1','m1','Values, types, and variables','JavaScript has 7 primitive types. `let` reassigns, `const` fixes the binding. `typeof null` returns "object", a bug frozen into the language in 1995.\n\n```js\nconst name = "orbit";\nlet count = 3;\n```\n\nEverything else — arrays, functions — is an object.',25,0),
 ('l2','m1','Operators and equality','Use `===` by default. `==` coerces first, so `0 == ""` is true. `a || b` returns `a` when truthy, else `b` — the common default-value pattern.',20,1),
 ('l3','m1','Branches and loops','`if` runs on truthy conditions; `else if` chains. Loops: `for` with a counter, `while` with a condition, `for...of` over values. `break` exits; `continue` skips.',30,2),
 ('l4','m2','Defining and calling functions','Declarations hoist, expressions don''t. No `return` means `undefined`. Arrow functions keep surrounding `this`, avoiding the classic callback bug.',30,3),
 ('l5','m2','Array methods: map, filter, reduce','`map` transforms 1:1, `filter` keeps matches, `reduce` folds to one value. All return new arrays and chain like a pipeline.',35,4),
 ('l6','m2','Objects and destructuring','`const { id, name } = user` pulls fields. `{...user, name: "x"}` copies without mutating. `Object.entries` makes objects mappable.',25,5),
 ('l7','m3','The DOM and events','The DOM is the live tree from your HTML. Use `addEventListener`, never inline attributes. Events bubble up the tree — one parent listener can delegate many children.',35,6),
 ('l8','m3','Async: timers, promises, fetch','One thing runs at a time; waiting work parks on the event loop. `await` reads top-to-bottom. Always handle rejection with try/catch.',40,7),
 ('l9','m4','HTML structure and semantics','Elements carry meaning: `<nav>`, `<main>`, `<button>`. Screen readers and search engines read structure; skip-level headings confuse both.',25,8),
 ('l10','m4','CSS layout with flex and grid','Flex distributes on one axis; grid handles two. `minmax()` + `auto-fit` gives responsive grids without media queries.',35,9),
 ('l11','m5','State and re-rendering','Keep minimal state, derive the rest. Update through a single setter so UI can''t drift from data.',30,10),
 ('l12','m5','Component boundaries and props','Split when reused, independently updated, or reasoned about alone. Data flows down as props, events flow up.',30,11),
 ('l13','m6','Breaking problems apart','Rewrite the prompt as input → output. Work a small example by hand, then write tests before code.',20,12),
 ('l14','m6','Complexity in plain terms','A nested loop over one list is O(n²) — 100M steps at n=10k. A scan plus hash-map lookup usually replaces it.',30,13),
 ('l15','m7','Hash maps for lookups','O(1) average lookup. Two-sum trick: store each value''s index and check complements — O(n) total.',30,14),
 ('l16','m7','Testing your own code','Test the guarantees, then the forgotten ones: empty input, size one, duplicates, max size. Read the failing output before editing.',25,15);

INSERT INTO lesson_skills (lesson_id, skill_id) VALUES
 ('l1','sk-jb'), ('l2','sk-jb'), ('l3','sk-cf'), ('l3','sk-jb'), ('l4','sk-fn'), ('l4','sk-jb'),
 ('l5','sk-ar'), ('l5','sk-fn'), ('l6','sk-ob'), ('l7','sk-dom'), ('l7','sk-jb'), ('l7','sk-hc'),
 ('l8','sk-asy'), ('l8','sk-fn'), ('l9','sk-hc'), ('l10','sk-hc'), ('l10','sk-dom'), ('l11','sk-dom'), ('l11','sk-fn'),
 ('l12','sk-dom'), ('l13','sk-pd'), ('l14','sk-pd'), ('l14','sk-ar'), ('l15','sk-ar'), ('l15','sk-ob'), ('l16','sk-td'), ('l16','sk-fn');

INSERT INTO course_skills (course_id, skill_id) VALUES
 ('c-jsf','sk-jb'),('c-jsf','sk-cf'),('c-jsf','sk-fn'),('c-jsf','sk-ar'),('c-jsf','sk-ob'),('c-jsf','sk-dom'),('c-jsf','sk-asy'),
 ('c-fend','sk-hc'),('c-fend','sk-dom'),('c-algo','sk-pd'),('c-algo','sk-ar'),('c-algo','sk-td');

INSERT INTO assessments (id, course_id, title, kind) VALUES ('a1','c-jsf','JavaScript placement','placement');

INSERT INTO assessment_questions (id, assessment_id, skill_id, type, prompt, options, answer, points, position) VALUES
 ('q1','a1','sk-jb','mcq','What does `typeof null` evaluate to?','["\"object\"","\"null\"","\"undefined\"","\"number\"']','"object"',10,0),
 ('q2','a1','sk-jb','mcq','Which value is NOT a JavaScript primitive?','["String","Boolean","Array","Symbol"]','Array',10,1),
 ('q3','a1','sk-cf','mcq','Inside a loop, what does `continue` do?','["Exits the loop entirely","Skips to the next iteration","Restarts the loop","Returns from the function"]','Skips to the next iteration',10,2),
 ('q4','a1','sk-cf','mcq','What does `while (0)` do?','["Never executes its body","Runs exactly once","Throws a TypeError","Loops forever"]','Never executes its body',10,3),
 ('q5','a1','sk-fn','mcq','What does `function f() { }` return when called as `f()`?','["undefined","null","0","It throws"]','undefined',10,4),
 ('q6','a1','sk-fn','short','In one word: which array method keeps only elements matching a predicate? (lowercase)',NULL,'filter',15,5),
 ('q7','a1','sk-ar','mcq','Which array method transforms each element and returns a new array of the same length?','["map","filter","find","some"]','map',10,6),
 ('q8','a1','sk-ar','mcq','What does `[1, 2, 3].reduce((a, b) => a + b, 10)` return?','["16","6","10","NaN"]','16',10,7),
 ('q9','a1','sk-asy','mcq','What is a promise?','["A function that always returns null","A placeholder for a value that will exist later","A synchronous callback queue","A type of array"]','A placeholder for a value that will exist later',10,8),
 ('q10','a1','sk-pd','short','In one word: where a nested loop scans a list, which data structure usually replaces the inner scan? (lowercase)',NULL,'map',15,9);

INSERT INTO coding_exercises (id, course_id, skill_id, title, prompt, language, starter_code) VALUES
 ('e-twosum','c-algo','sk-ar','Two Sum',
  'Write `solve({ nums, target })`. Return the zero-based indices `[i, j]` (i < j) of the two numbers that add up to `target`. Exactly one solution exists in every test input. Aim for a single pass with a hash map — the hidden case uses 100,000 items, so an O(n²) double loop will time out.',
  'javascript','// solve receives one object argument: { nums, target }\nfunction solve({ nums, target }) {\n  \n}\n'),
 ('e-fizz','c-algo','sk-pd','FizzBuzz',
  'Write `solve({ n })`. Return an array of the numbers 1..n as strings, except multiples of 3 become "Fizz", multiples of 5 become "Buzz", and multiples of both become "FizzBuzz".',
  'javascript','// Input: { n }. Output: array of strings of length n.\nfunction solve({ n }) {\n  \n}\n'),
 ('e-orbit','c-fend','sk-hc','Assignment — Orbit Loader',
  'Build a loading spinner for EduSphere using only HTML + CSS + JS in the preview panel: one central dot, one ring rotating around it, and one small node riding the ring. Animate with CSS `transform` + `animation`. When it works, submit for review — it goes to 3 anonymous classmates with the UI-craft rubric.',
  'html','<div class="loader">\n\n</div>\n\n<style>\n  body { background: #071726; display: grid; place-items: center; min-height: 90vh; }\n</style>\n');

INSERT INTO test_cases (id, exercise_id, input, expected, hidden, position) VALUES
 ('t1','e-twosum','{"nums":[2,7,11,15],"target":9}','[0,1]',0,0),
 ('t2','e-twosum','{"nums":[3,2,4],"target":6}','[1,2]',0,1),
 ('t3','e-twosum','{"nums":[-3,4,3,90],"target":0}','[0,2]',0,2),
 -- Build the 100k case at import time: nums = 0..99999, unique solution at the very end.
 ('t4','e-fizz','{"n":5}','["1","2","Fizz","4","Buzz"]',0,0),
 ('t5','e-fizz','{"n":15}','["1","2","Fizz","4","Buzz","Fizz","7","8","Fizz","Buzz","11","Fizz","13","14","FizzBuzz"]',0,1),
 ('t6','e-fizz','{"n":1}','["1"]',1,2);

-- The 100,000-item hidden two-sum case, generated without client-side code.
INSERT INTO test_cases (id, exercise_id, input, expected, hidden, position)
SELECT 't7','e-twosum',
  CONCAT('{"nums":[', GROUP_CONCAT(n ORDER BY n SEPARATOR ','), '],"target":199997}'),
  '[99998,99999]', 1, 3
FROM (WITH RECURSIVE seq(n) AS (SELECT 0 UNION ALL SELECT n + 1 FROM seq WHERE n < 99999) SELECT n FROM seq) s;

INSERT INTO rubrics (id, course_id, title) VALUES ('r1','c-fend','UI craft rubric');
INSERT INTO rubric_criteria (id, rubric_id, label, description, max_points, position) VALUES
 ('rc1','r1','Correct behavior','Does the loader render and animate as required, with no console errors?',10,0),
 ('rc2','r1','Code clarity','Readable structure, descriptive names, no dead code.',10,1),
 ('rc3','r1','Visual craft','Spacing, color, and motion feel deliberate rather than default.',10,2),
 ('rc4','r1','Requirements fit','Central dot, rotating ring, riding node — all three present.',10,3);

INSERT INTO badges (id, code, name, description, icon, accent, xp_reward) VALUES
 ('b-pf','pathfinder','Pathfinder','Generated a learning path from placement results.','compass','indigo',25),
 ('b-fs','first-steps','First Steps','Completed the first lesson on your path.','footprints','mint',25),
 ('b-s3','streak-3','Kindling','Reached a 3-day learning streak.','flame','amber',30),
 ('b-s7','streak-7','Bonfire','Reached a 7-day learning streak.','flame','amber',80),
 ('b-bh','bug-hunter','Bug Hunter','Passed every test case on a coding exercise.','bug','mint',40),
 ('b-rv','reviewer','Peer Reviewer','Submitted a written peer review.','handshake','indigo',40),
 ('b-sm','sharp-mind','Sharp Mind','Scored 90% or higher on a placement assessment.','zap','coral',50),
 ('b-co','constellation','Constellation','Completed every lesson on a learning path.','orbit','coral',100);

-- Aria's populated history: placement, gap scores, active path, progress, streaks.
INSERT INTO assessment_attempts (id, assessment_id, user_id, status, score, started_at, submitted_at)
 VALUES ('att-aria','a1','u-aria','submitted',52.00,DATE_SUB(NOW(), INTERVAL 6 DAY),DATE_SUB(NOW(), INTERVAL 6 DAY));
INSERT INTO student_skill_scores (id, user_id, skill_id, source, score, measured_at) VALUES
 (UUID(),'u-aria','sk-jb','assessment',80,DATE_SUB(NOW(), INTERVAL 6 DAY)),
 (UUID(),'u-aria','sk-cf','assessment',70,DATE_SUB(NOW(), INTERVAL 6 DAY)),
 (UUID(),'u-aria','sk-fn','assessment',60,DATE_SUB(NOW(), INTERVAL 6 DAY)),
 (UUID(),'u-aria','sk-ar','assessment',40,DATE_SUB(NOW(), INTERVAL 6 DAY)),
 (UUID(),'u-aria','sk-ob','assessment',30,DATE_SUB(NOW(), INTERVAL 6 DAY)),
 (UUID(),'u-aria','sk-dom','assessment',25,DATE_SUB(NOW(), INTERVAL 6 DAY)),
 (UUID(),'u-aria','sk-asy','assessment',20,DATE_SUB(NOW(), INTERVAL 6 DAY)),
 (UUID(),'u-aria','sk-pd','assessment',35,DATE_SUB(NOW(), INTERVAL 6 DAY));

INSERT INTO learning_paths (id, user_id, course_id, status, velocity, rationale, generated_at) VALUES
 ('p-aria','u-aria','c-jsf','active',0.80,
  'Placement scored Arrays & Iteration at 40%, Objects & Data at 30%, DOM at 25%, and Async at 20% against JavaScript Basics at 80%. The path opens with the two strongest foundations as quick wins, then spends most items on arrays, objects, and DOM before closing with async. Pacing assumes about 0.8 completed lessons per day from the last two weeks.',
  DATE_SUB(NOW(), INTERVAL 5 DAY));
INSERT INTO learning_path_items (id, path_id, lesson_id, position, status, completed_at) VALUES
 (UUID(),'p-aria','l1',0,'done',DATE_SUB(NOW(), INTERVAL 3 DAY)),
 (UUID(),'p-aria','l2',1,'done',DATE_SUB(NOW(), INTERVAL 2 DAY)),
 (UUID(),'p-aria','l5',2,'current',NULL),
 (UUID(),'p-aria','l6',3,'locked',NULL),
 (UUID(),'p-aria','l3',4,'locked',NULL),
 (UUID(),'p-aria','l4',5,'locked',NULL),
 (UUID(),'p-aria','l7',6,'locked',NULL),
 (UUID(),'p-aria','l8',7,'locked',NULL);
INSERT INTO progress_records (id, user_id, lesson_id, time_spent_sec, completed_at) VALUES
 (UUID(),'u-aria','l1',1140,DATE_SUB(NOW(), INTERVAL 4 DAY)),
 (UUID(),'u-aria','l2',900,DATE_SUB(NOW(), INTERVAL 3 DAY));
INSERT INTO execution_results (id, user_id, exercise_id, stdout, stderr, runtime_ms, passed_count, total_count, results, created_at) VALUES
 (UUID(),'u-aria','e-twosum','','',12,2,4,'[]',DATE_SUB(NOW(), INTERVAL 2 DAY)),
 (UUID(),'u-aria','e-fizz','["1","2","Fizz"]','',4,3,3,'[]',DATE_SUB(NOW(), INTERVAL 1 DAY));

INSERT INTO streaks (id, user_id, current, longest, last_activity_date) VALUES
 (UUID(),'u-aria',3,5,CURDATE()), (UUID(),'u-theo',2,4,CURDATE()), (UUID(),'u-noor',6,9,CURDATE()),
 (UUID(),'u-felix',1,2,CURDATE()), (UUID(),'u-june',0,3,CURDATE()), (UUID(),'u-soleil',4,4,CURDATE()),
 (UUID(),'u-dev',8,8,CURDATE()), (UUID(),'u-maya',1,2,CURDATE()), (UUID(),'u-kir',3,5,CURDATE()),
 (UUID(),'u-owen',1,1,CURDATE());

INSERT INTO user_badges (id, user_id, badge_id, awarded_at) VALUES
 (UUID(),'u-aria','b-pf',DATE_SUB(NOW(), INTERVAL 5 DAY)),
 (UUID(),'u-aria','b-fs',DATE_SUB(NOW(), INTERVAL 4 DAY)),
 (UUID(),'u-aria','b-s3',NOW()),
 (UUID(),'u-dev','b-s7',DATE_SUB(NOW(), INTERVAL 1 DAY)),
 (UUID(),'u-theo','b-bh',DATE_SUB(NOW(), INTERVAL 2 DAY));

INSERT INTO certificates (id, user_id, course_id, code, issued_at) VALUES
 (UUID(),'u-aria','c-fend','EDU-7Q4M-92XT',DATE_SUB(NOW(), INTERVAL 14 DAY));

-- Peer review graph: Noor's Orbit Loader awaits Aria's review; Aria's has one review back from June.
SET @loader := '<div class="loader"><span class="core"></span><span class="ring"><span class="node"></span></span></div>\n<style>\n  body{background:#071726;display:grid;place-items:center;min-height:90vh}\n  .loader{position:relative;width:96px;height:96px}\n  .core{position:absolute;inset:38px;border-radius:50%;background:#5b9bd8}\n  .ring{position:absolute;inset:0;border:1px solid #3fc4b866;border-radius:50%;animation:spin 2.4s linear infinite}\n  .node{position:absolute;top:-5px;left:50%;width:10px;height:10px;border-radius:50%;background:#3fc4b8}\n  @keyframes spin{to{transform:rotate(360deg)}}\n</style>';
INSERT INTO submissions (id, user_id, exercise_id, kind, title, code, language, status, created_at) VALUES
 ('sub-noor','u-noor','e-orbit','assignment','Orbit Loader',CONCAT(@loader,'\n<!-- noor draft 2 -->'),'html','reviewing',DATE_SUB(NOW(), INTERVAL 2 DAY)),
 ('sub-aria','u-aria','e-orbit','assignment','Orbit Loader',CONCAT(@loader,'\n<!-- aria draft 1 -->'),'html','reviewing',DATE_SUB(NOW(), INTERVAL 3 DAY));
INSERT INTO peer_assignments (id, submission_id, reviewer_id, status, assigned_at, completed_at) VALUES
 ('pa-aria1','sub-noor','u-aria','pending',DATE_SUB(NOW(), INTERVAL 1 DAY),NULL),
 ('pa-theo','sub-aria','u-theo','pending',DATE_SUB(NOW(), INTERVAL 2 DAY),NULL),
 ('pa-june','sub-aria','u-june','submitted',DATE_SUB(NOW(), INTERVAL 2 DAY),DATE_SUB(NOW(), INTERVAL 1 DAY)),
 ('pa-felix','sub-noor','u-felix','pending',DATE_SUB(NOW(), INTERVAL 1 DAY),NULL);
INSERT INTO peer_reviews (id, assignment_id, submission_id, reviewer_id, overall_comment, created_at) VALUES
 ('rev-june','pa-june','sub-aria','u-june',
  'The ring motion is smooth and the teal node reads clearly against the dark background. Tabular naming (`spin`, `core`, `node`) made the CSS quick to follow. The core dot sits 1px off-center at 96px because inset doesn''t account for its own size — translate(-50%,-50%) from the parent center would fix it. No console errors.',
  DATE_SUB(NOW(), INTERVAL 1 DAY));
INSERT INTO review_scores (id, review_id, criterion_id, score) VALUES
 (UUID(),'rev-june','rc1',9),(UUID(),'rev-june','rc2',8),(UUID(),'rev-june','rc3',9),(UUID(),'rev-june','rc4',10);

INSERT INTO live_sessions (id, course_id, host_id, title, starts_at, duration_min, room_code, join_url) VALUES
 (UUID(),'c-jsf','u-priya','Async debugging clinic',DATE_ADD(NOW(), INTERVAL 2 DAY),60,'edusphere-async-clinic','https://meet.jit.si/edusphere-async-clinic'),
 (UUID(),'c-algo','u-marco','Two-sum walkthrough and variants',DATE_ADD(NOW(), INTERVAL 5 DAY),45,'edusphere-twosum-lab','https://meet.jit.si/edusphere-twosum-lab');

INSERT INTO notifications (id, user_id, type, title, body, created_at) VALUES
 (UUID(),'u-aria','review_assigned','A peer submission is waiting for your review','One Orbit Loader submission was added to your review queue.',DATE_SUB(NOW(), INTERVAL 1 DAY)),
 (UUID(),'u-aria','review_received','New feedback on your Orbit Loader','A reviewer finished scoring your submission. Average so far: 9.0/10.',DATE_SUB(NOW(), INTERVAL 1 DAY)),
 (UUID(),'u-aria','badge','Badge earned: Kindling','3-day streak. Keep it alive tomorrow.',NOW());

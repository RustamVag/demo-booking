# Ревью `BookingController`

Ревью статическое: Laravel 13, PHP 8.4, PostgreSQL. Ссылки на строки соответствуют текущей версии
[`BookingController.php`](app/Http/Controllers/BookingController.php).

Контекст, важный для выводов:

- эндпоинты закрыты `auth:sanctum` ([`routes/api.php:6`](routes/api.php:6));
- у офиса есть часовой пояс `offices.timezone`, у комнаты — `rooms.office_id`
  ([`2026_10_29_000001_create_offices_table.php:14`](database/migrations/2026_10_29_000001_create_offices_table.php:14));
- `starts_at` / `ends_at` — `timestamp` **без** часового пояса
  ([`2026_10_29_000003_create_bookings_table.php:15`](database/migrations/2026_10_29_000003_create_bookings_table.php:15)),
  а `config('app.timezone')` — `UTC` ([`config/app.php:68`](config/app.php:68));
- в модели `Booking` поля `room_id`, `user_id`, `title`, `starts_at`, `ends_at` доступны для массового
  заполнения ([`Booking.php:11`](app/Models/Booking.php:11)).

Критерии серьёзности:

| Уровень | Что означает |
| --- | --- |
| **Critical** | Уязвимость, обход авторизации, гарантированная ошибка 500 или порча данных. Исправлять до выката. |
| **Major** | Нарушение бизнес-правил из задания, неверные результаты, гонки, заметные проблемы производительности. |
| **Minor** | Стиль, конвенции, формат ответа, наблюдаемость. На работу не влияет или влияет слабо. |

## Сводная таблица

| # | Серьёзность | Строки | Проблема |
| --- | --- | --- | --- |
| C1 | **Critical** | 23–26 | SQL-инъекция: значения из запроса подставляются в SQL строкой |
| C2 | **Critical** | 23–37 | Нет валидации; `user_id` берётся из запроса и подставляется в `create()` |
| C3 | **Critical** | 45–50 | `destroy()` не проверяет, что бронь принадлежит текущему пользователю |
| M1 | **Major** | 23–26 | Условие пересечения замкнутое (`<=` / `>=`), встречи «впритык» отклоняются |
| M2 | **Major** | 32–35 | Рабочие часы проверяются по смещению клиента, а не по TZ офиса; `ends_at` не проверяется |
| M3 | **Major** | 23–26, 37 | Часовые пояса: в `timestamp` без TZ пишется локальное время клиента, смещение при сравнении игнорируется |
| M4 | **Major** | 32–37 | Не проверяются длительность 15 мин – 4 ч и шаг 15 минут |
| M5 | **Major** | 32–37 | Не проверяется, что `ends_at > starts_at` |
| M6 | **Major** | 23–37 | Гонка между проверкой конфликта и вставкой: возможна двойная бронь |
| M7 | **Major** | 55 | `today()` считает «сегодня» в UTC, а не в часовом поясе офиса |
| M8 | **Major** | 59 | N+1: `$b->user->name` грузит пользователя для каждой брони |
| m1 | **Minor** | 39–40 | `Log::withContext()` не применяется к каналу `bookings`; контекст может «утекать» |
| m2 | **Minor** | 42 | Возврат 200 и модели целиком вместо 201 + API Resource |
| m3 | **Minor** | 54–60 | `time` склеивается из `Carbon` (дата и секунды), нет сортировки |
| m4 | **Minor** | 17 | `orderByRaw()` без параметров безопасен, но лишний |
| m5 | **Minor** | 14, 21, 45, 52 | Нет объявленных возвращаемых типов |
| m6 | **Minor** | 45 | `destroy($id)` без `whereNumber`: нечисловой id даёт ошибку PostgreSQL (500) |
| m7 | **Minor** | 52 | `today()` доступен любому авторизованному пользователю и отдаёт имена авторов |
| m8 | **Minor** | 23–35 | Конфликт проверяется раньше валидации: клиент получает 409 вместо 422 |
| m9 | **Minor** | — | Нет feature-тестов на `store`, `destroy`, `today` |

---

## Critical

### C1. SQL-инъекция в `store()` — строки 23–26

```php
$conflict = DB::select("SELECT id FROM bookings
    WHERE room_id = {$request->room_id}
    AND starts_at <= '{$request->ends_at}'
    AND ends_at >= '{$request->starts_at}'");
```

Значения из запроса вставляются в SQL напрямую. Это классическая SQL-инъекция: через `room_id`,
`starts_at` или `ends_at` можно передать произвольный SQL (`1 OR 1=1`, подзапросы, `pg_sleep()` и т.д.).
Кроме того, отсутствие параметра даёт `NULL`/пустую строку, и запрос падает с ошибкой PostgreSQL —
клиент получает 500.

**Как исправить:** использовать Query Builder / Eloquent с биндингами (никогда не склеивать SQL):

```php
$isBooked = Booking::query()
    ->where('room_id', $data['room_id'])
    ->where('starts_at', '<', $endUtc)
    ->where('ends_at', '>', $startUtc)
    ->exists();
```

`DB::select()` здесь не нужен: нужен только факт наличия записи, а `exists()` эффективнее и безопаснее.

### C2. Нет валидации, массовое заполнение и подмена `user_id` — строка 37

```php
$booking = Booking::create($request->all());
```

Проблем сразу несколько:

1. **Подмена автора.** `user_id` есть в `#[Fillable]` ([`Booking.php:11`](app/Models/Booking.php:11)),
   поэтому клиент может создать бронь от имени любого пользователя, передав `user_id`. Это обход
   авторизации и порча данных.
2. **Нет валидации.** Отсутствие `room_id`, `title`, `starts_at`, `ends_at` приводит к нарушению
   `NOT NULL` и ошибке 500 вместо понятной 422. Нечисловой `room_id` на PostgreSQL даёт
   `invalid input syntax`, слишком длинный `title` — ошибку вставки.
3. **Нет проверки существования комнаты** — нарушение внешнего ключа снова даёт 500.
4. `$request->all()` передаёт лишние поля (они отфильтруются `#[Fillable]`, но это не аргумент
   в пользу передачи всего запроса).
5. `$request->starts_at` — «магическое» свойство. Если клиент пришлёт массив, получим
   `Array to string conversion` / исключение парсинга даты, то есть 500.

**Как исправить:** валидировать вход и всегда брать автора из аутентификации:

```php
$data = $request->validate([
    'room_id'   => ['required', 'integer', 'exists:rooms,id'],
    'title'     => ['required', 'string', 'max:255'],
    'starts_at' => ['required', 'date'],
    'ends_at'   => ['required', 'date', 'after:starts_at'],
]);

$booking = Booking::create([
    'room_id'   => $data['room_id'],
    'user_id'   => $request->user()->id, // никогда не из запроса
    'title'     => $data['title'],
    'starts_at' => $startUtc,
    'ends_at'   => $endUtc,
]);
```

`user_id` можно оставить в `#[Fillable]`, но устанавливать его только на сервере. Альтернатива —
вынести правила в Form Request и использовать `$request->safe()->only([...])`.

### C3. `destroy()` не проверяет автора — строки 45–50

```php
Booking::findOrFail($id)->delete();
```

Любой авторизованный пользователь может удалить любую бронь, зная её `id`. Это broken access
control: требование «отменить бронь может только её автор» не выполняется.

**Как исправить:** ограничить выборку текущим пользователем (вернёт 404 для чужой брони — не
раскрывает существование) или явно вернуть 403:

```php
public function destroy(Request $request, string $id)
{
    $booking = $request->user()->bookings()->findOrFail($id);

    $booking->delete();

    return response()->noContent();
}
```

Вариант с route model binding и политикой:

```php
public function destroy(Request $request, Booking $booking)
{
    abort_unless($booking->user_id === $request->user()->id, 403);
    $booking->delete();

    return response()->noContent();
}
```

Для второго варианта нужно поменять маршрут на `{booking}` и добавить `->whereNumber('booking')`,
иначе нечисловой id снова даст ошибку PostgreSQL (см. m6).

---

## Major

### M1. Неверное условие пересечения — строки 23–26

```php
AND starts_at <= '{$request->ends_at}'
AND ends_at >= '{$request->starts_at}'
```

Условие замкнутое. Для броней 10:00–11:00 и 11:00–12:00 получим `11:00 <= 11:00` и
`11:00 >= 11:00`, то есть ложное пересечение, хотя по заданию встречи «впритык» разрешены.
Правильная семантика пересечения — полуинтервалы `[start, end)`:

```php
$isBooked = Booking::query()
    ->where('room_id', $roomId)
    ->where('starts_at', '<', $newEnd)
    ->where('ends_at', '>', $newStart)
    ->exists();
```

То есть «существующая начинается раньше, чем заканчивается новая» **и** «заканчивается позже, чем
начинается новая».

### M2. Рабочие часы проверяются не по времени офиса и только для начала — строки 32–35

```php
$start = Carbon::parse($request->starts_at);
if ($start->hour < 9 || $start->hour >= 20) { ... }
```

Проблемы:

1. `Carbon::parse()` сохраняет смещение из строки клиента, поэтому `hour` — это час **клиента**, а не
   офиса. Клиент может прислать любое смещение и обойти проверку: для московской комнаты
   `09:00+00:00` — это 12:00 MSK (проходит, хотя фактически рабочее), а `20:00+00:00` — 23:00 MSK
   (тоже проходит). Обратная ситуация: корректное `10:00 MSK`, отправленное как `07:00Z`, будет
   отклонено.
2. Проверяется только `starts_at`. Бронь 19:30–20:30 или 19:00–23:00 пройдёт проверку, хотя не
   укладывается в 09:00–20:00.
3. Не проверяется, что начало и конец в один календарный день (при разрешённых 4 часах это
   следствие, но лучше проверить явно).

**Как исправить:** привести обе даты к часовому поясу офиса и проверить весь интервал:

```php
$room = Room::with('office')->findOrFail($data['room_id']);
$timezone = $room->office->timezone;

$start = Carbon::parse($data['starts_at'])->setTimezone($timezone);
$end   = Carbon::parse($data['ends_at'])->setTimezone($timezone);

$opensAt  = $start->copy()->setTime(9, 0);
$closesAt = $start->copy()->setTime(20, 0);

if ($start->lt($opensAt) || $end->gt($closesAt) || ! $start->isSameDay($end)) {
    throw ValidationException::withMessages([
        'starts_at' => 'Бронь должна целиком попадать в рабочее время 09:00–20:00 по времени офиса.',
    ]);
}
```

### M3. Часовые пояса при хранении и сравнении — строки 23–26, 37

Колонки `starts_at` / `ends_at` — `timestamp without time zone`, приложение живёт в UTC, а клиенты
присылают ISO 8601 с произвольным смещением. В текущем коде:

- Eloquent сохраняет `Carbon` в том часовом поясе, который был в строке клиента, **без** приведения
  к UTC: в базе оказываются локальные времена разных смещений, которые нельзя сравнивать между собой;
- PostgreSQL для `timestamp without time zone` при разборе строкового литерала **молча игнорирует
  смещение** (`'2026-11-02T10:00:00+03:00'` сравнится как `10:00`), а `Carbon::parse(...)->hour`
  смещение учитывает. Проверки в SQL и в PHP расходятся;
- сравнение строк в SQL к тому же зависит от формата, который прислал клиент.

**Как исправить:** зафиксировать единую базу — хранить UTC.

```php
$startUtc = Carbon::parse($data['starts_at'])->utc();
$endUtc   = Carbon::parse($data['ends_at'])->utc();
```

- сохранять в БД именно `$startUtc` / `$endUtc`;
- все сравнения конфликтов делать в UTC (значения из БД уже UTC);
- для отображения (`today()`) конвертировать в TZ офиса;
- в идеале миграцией перейти на `timestamptz` (или как минимум задокументировать, что колонки
  содержат UTC). Для `timestamptz` PostgreSQL сравнивает моменты времени корректно независимо от
  смещения в литерале.

### M4. Не проверяются длительность и шаг 15 минут — строки 32–37

Требование: длительность от 15 минут до 4 часов, шаг 15 минут. В контроллере этого нет вообще,
поэтому можно создать бронь на 1 минуту, на 10 часов или на 10:07.

**Как исправить:** после нормализации дат:

```php
$duration = (int) $start->diffInMinutes($end);

$validGrid = $start->second === 0 && $end->second === 0
    && $start->minute % 15 === 0 && $end->minute % 15 === 0;

if (! $validGrid || $duration < 15 || $duration > 240) {
    throw ValidationException::withMessages([
        'starts_at' => 'Длительность — от 15 минут до 4 часов с шагом 15 минут.',
    ]);
}
```

### M5. Не проверяется `ends_at > starts_at` — строки 32–37

Без валидации можно создать бронь с `ends_at <= starts_at` (нулевая или отрицательная
длительность). Это ломает и проверку длительности, и логику пересечений. В `validate()` нужно
правило `after:starts_at`, плюс явная проверка после приведения к TZ офиса (`$end->lte($start)`),
потому что сравнение строк с разными смещениями неочевидно.

### M6. Гонка между проверкой и вставкой — строки 23–37

Между `SELECT` конфликта и `INSERT` нет транзакции и блокировки. Два одновременных запроса на один
слот оба увидят «свободно» и создадут две брони (TOCTOU). Задание помечает это как бонус, но
архитектурно это дыра в целостности данных.

**Как исправить (варианты):**

1. Транзакция + блокировка строки комнаты как мьютекса:

```php
$booking = DB::transaction(function () use (...) {
    Room::whereKey($room->id)->lockForUpdate()->first();

    $isBooked = Booking::query()
        ->where('room_id', $room->id)
        ->where('starts_at', '<', $endUtc)
        ->where('ends_at', '>', $startUtc)
        ->exists();

    if ($isBooked) {
        abort(response()->json(['error' => 'Комната занята'], 409));
    }

    return Booking::create([...]);
});
```

2. Ограничение на уровне БД (надёжнее всего) — exclusion constraint:

```sql
CREATE EXTENSION IF NOT EXISTS btree_gist;

ALTER TABLE bookings
    ADD CONSTRAINT bookings_no_overlap
    EXCLUDE USING gist (
        room_id WITH =,
        tsrange(starts_at, ends_at, '[)') WITH &&
    );
```

Тогда пересечение невозможно даже при ошибке в коде; `QueryException` нужно будет превращать в 409.
Для `timestamptz` использовать `tstzrange`. Альтернатива — `pg_advisory_xact_lock(room_id)`.

### M7. `today()` считает сутки в UTC — строка 55

```php
->whereDate('starts_at', today())
```

`today()` использует `config('app.timezone')`, то есть UTC. Для Новосибирска (UTC+7) и Москвы
(UTC+3) границы суток не совпадают с локальными: например, в 02:00 по Новосибирску в UTC ещё
предыдущий день, и метод вернёт не те брони.

**Как исправить:** вычислить локальные границы суток и перевести их в UTC:

```php
$timezone  = $room->office->timezone;
$startOfDay = Carbon::now($timezone)->startOfDay()->utc();
$endOfDay   = $startOfDay->copy()->addDay();

$room->bookings()
    ->where('starts_at', '>=', $startOfDay)
    ->where('starts_at', '<', $endOfDay)
    ->get();
```

(Здесь предполагается, что в БД хранится UTC, см. M3.)

### M8. N+1 в `today()` — строка 59

```php
->map(fn ($b) => [ ... 'author' => $b->user->name ])
```

Для каждой брони выполняется отдельный запрос пользователя. Нужно предзагрузить связь:

```php
$room->bookings()
    ->with('user:id,name')
    ->where(...)
    ->get()
    ->map(fn (Booking $b) => [
        'time'   => $b->starts_at->setTimezone($timezone)->format('H:i')
            .' – '.$b->ends_at->setTimezone($timezone)->format('H:i'),
        'author' => $b->user->name,
    ]);
```

### M9. Нет ограничения на частоту запросов (throttling)

При желании можно довольно быстро забить все свободные слоты на долго вперед.

---

## Minor

### m1. `Log::withContext()` не попадает в канал `bookings` — строки 39–40

```php
Log::withContext(['booking_id' => $booking->id]);
Log::channel('bookings')->info('Booking created');
```

`Log` — это `LogManager`; `Log::withContext()` через `__call()` уходит в **драйвер по умолчанию**
(`stack`), а `Log::channel('bookings')` — отдельный экземпляр `Logger` со своим контекстом. В итоге
`booking_id` в `storage/logs/bookings.log` не попадёт. Дополнительно контекст, установленный на
разделяемом логгере, в долгоживущих процессах (Octane, очереди) может «утечь» в следующие запросы.

**Как исправить:** передавать контекст непосредственно в вызов нужного канала:

```php
Log::channel('bookings')->info('Booking created', ['booking_id' => $booking->id]);
```

### m2. Ответ `store()` — 200 и модель целиком — строка 42

По REST создание ресурса — это `201 Created` (обычно с `Location`). Сейчас возвращается 200 и вся
модель, включая `user_id`, `room_id` и служебные поля. Лучше вернуть `201` и Eloquent API Resource,
который явно описывает публичные поля:

```php
return BookingResource::make($booking)->response()->setStatusCode(201);
```

### m3. Формат `time` в `today()` — строки 54–60

```php
'time' => $b->starts_at . ' – ' . $b->ends_at,
```

`__toString()` у `Carbon` даёт `Y-m-d H:i:s`, то есть в `time` попадут дата и секунды, а не только
часы. Плюс нет сортировки по времени. Нужно форматировать в TZ офиса и сортировать:

```php
->orderBy('starts_at')
->map(fn (Booking $b) => [
    'time' => $b->starts_at->setTimezone($timezone)->format('H:i')
        .' – '.$b->ends_at->setTimezone($timezone)->format('H:i'),
    ...
]);
```

### m4. `orderByRaw()` в `index()` — строка 17

```php
->orderByRaw('starts_at DESC')
```

Параметров нет, инъекции здесь нет — это не уязвимость. Но `orderByRaw()` без причины избыточен:
`->orderByDesc('starts_at')` читается лучше и переносимee между драйверами. Поведение `index()`
корректно и покрыто тестом [`BookingIndexTest.php`](tests/Feature/BookingIndexTest.php).

### m5. Нет объявленных возвращаемых типов — строки 14, 21, 45, 52

У методов нет `: JsonResponse`, `: LengthAwarePaginator`, `: Response` и т.п. Это нарушение
конвенций проекта (явные типы). Например: `public function destroy(Request $request, string $id): Response`.

### m6. `destroy($id)` без ограничения маршрута — строка 45

Если `id` не число, PostgreSQL вернёт `invalid input syntax for type bigint`, и клиент получит 500
вместо 404. Нужен `->whereNumber('id')` в маршруте либо route model binding с `whereNumber`.
Более надёжный вариант — ограничить выборку пользователем (см. C3), но проверка формата всё равно
нужна.

### m7. `today()` без авторизации и приватность — строка 52

Метод доступен любому аутентифицированному пользователю для любой комнаты и отдаёт имена авторов.
Если расписание переговорных считается внутренним и общим — это допустимо. Если нет — нужна
политика/фильтрация полей. По заданию `today()` менять не требуется, но стоит зафиксировать решение.

### m8. Порядок проверок: 409 раньше 422 — строки 23–35

Проверка конфликта выполняется до валидации формата и рабочих часов. Для некорректного запроса
клиент получит 409 «Комната занята» вместо 422 с описанием ошибки. Сначала валидация, затем
бизнес-правила.

### m9. Нет тестов на `store()`, `destroy()`, `today()`

Есть только тесты `index()` ([`BookingIndexTest.php`](tests/Feature/BookingIndexTest.php)). Именно в
`store()` и `destroy()` сосредоточены все критичные проблемы, поэтому нужны feature-тесты:
пересечения и «впритык», границы 09:00/20:00 в TZ офиса, длительность/шаг, отмена чужой брони,
422 на некорректный ввод, 409 на занятый слот.

### m10. Возможность ввода любых дат за переделами ограничений

Можно вводить любые не конфликтующие даты, например прошлые. Не стал это проверять в коде, так как в Readme написано, что других ограничений на даты нет.

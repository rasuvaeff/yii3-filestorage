# rasuvaeff/yii3-filestorage

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/yii3-filestorage/v)](https://packagist.org/packages/rasuvaeff/yii3-filestorage)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-filestorage/downloads)](https://packagist.org/packages/rasuvaeff/yii3-filestorage)
[![Build](https://github.com/rasuvaeff/yii3-filestorage/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-filestorage/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/yii3-filestorage/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-filestorage/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/yii3-filestorage/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-filestorage/php)](https://packagist.org/packages/rasuvaeff/yii3-filestorage)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
[English version](README.md)

Один фасад — `add()`, `find()`, `remove()`, `stream()`, `urlFor()` — поверх
сменного физического бэкенда и сменного бэкенда метаданных. Сквозной PSR-7
стриминг, авторитетное определение MIME, правила приёма на группу и выдача,
которая не отдаст загруженный HTML с вашего же origin.

> Используете ИИ-ассистента? В [llms.txt](llms.txt) лежит компактный справочник по API — его можно передать модели.
> В проектах с Composer-плагином [llm/skills](https://github.com/roxblnfk/skills) скилл пакета синхронизируется в `.agents/skills/` автоматически при установке.

**Статус: `0.x`.** API ещё может меняться, пока под него строятся бэкенды для
БД и Flysystem. Раскладка путей и формат подписанного токена уже заморожены —
см. [Замороженные решения](#замороженные-решения).

## Требования

- PHP 8.3+
- `ext-fileinfo`
- `psr/clock` ^1.0
- `psr/http-message` ^2.0, `psr/http-factory` ^1.0 (плюс реализация PSR-17 в
  приложении — например `nyholm/psr7` или `httpsoft/http-message`)
- `symfony/console` ^6.4 || ^7.0 || ^8.0
- `symfony/mime` ^6.4 || ^7.0 || ^8.0
- `yiisoft/files` ^2.0

## Установка

```bash
composer require rasuvaeff/yii3-filestorage
```

Ядро сознательно неполно само по себе: оно биндит фасад и собственные сервисы,
но **не** `StoreInterface` и **не** `RepositoryInterface`. Их даёт пакет-бэкенд
либо само приложение.

| Что нужно | Что установить / забиндить |
|---|---|
| Файлы на локальном диске, метаданные в БД | этот пакет + `rasuvaeff/yii3-filestorage-db`, забиндить `FileSystemStore` |
| Файлы в S3/GCS/Azure | этот пакет + `rasuvaeff/yii3-filestorage-flysystem` |
| Подписанные URL, загрузка, поддержка `Range` | добавить `rasuvaeff/yii3-filestorage-web` |
| Просто попробовать | забиндить `FileSystemStore` и `Test\MemoryRepository` вручную (ниже) |

## Использование

### Проводка

```php
// config/common/di/filestorage.php
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Yii3Filestorage\Repository\RepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Store\FileSystem\FileSystemStore;
use Rasuvaeff\Yii3Filestorage\Store\StoreInterface;
use Rasuvaeff\Yii3Filestorage\Test\MemoryRepository;

return [
    StoreInterface::class => static fn (StreamFactoryInterface $streams): StoreInterface
        => new FileSystemStore(
            name: 'upload',
            rootPath: '/app/runtime/upload',
            streamFactory: $streams,
        ),

    // Только для разработки — все записи теряются вместе с процессом.
    RepositoryInterface::class => MemoryRepository::class,
];
```

Затем проверить:

```bash
./yii filestorage:check
```

### Сохранение

```php
use Rasuvaeff\Yii3Filestorage\StorageInterface;
use Rasuvaeff\Yii3Filestorage\Upload;

// Из HTTP-загрузки.
$file = $storage->add(
    Upload::fromUploadedFile($request->getUploadedFiles()['avatar'], $streamFactory),
    groupName: 'avatars',
);

// Из того, что сгенерировало приложение — отрендеренный PDF, выгрузка.
$file = $storage->add(
    Upload::fromStream($pdfStream, 'invoice-2026-08.pdf', $streamFactory),
    groupName: 'documents',
    description: 'Счёт за август',
    metadata: ['invoiceId' => 4711],
);

// Из пути.
$file = $storage->add(Upload::fromPath('/tmp/import.csv', $streamFactory));
```

`$file` — неизменяемый `File`: `id`, `storeName`, `groupName`, `relativePath`,
`originalName`, `mimeType`, `size`, `description`, `contentHash`, `metadata`,
`createdAt`, `updatedAt`. `toArray()` / `fromArray()` дают точный round-trip,
включая микросекунды.

### Чтение

```php
$file = $storage->find($id);

$stream = $storage->stream($file);   // PSR-7, основной путь чтения
$bytes  = $storage->content($file);  // с ограничением; бросает ContentTooLargeException
$there  = $storage->exists($file);   // лежат ли байты на месте физически
```

### URL

```php
$url = $storage->urlFor($file);                    // тот, который нужно звать
$url = $storage->urlFor($file, $expiresAt);        // явный срок жизни
```

`urlFor()` применяет политику выдачи группы и затем пробует по порядку:

1. постоянный публичный URL — **только** если группа это явно разрешает;
2. presigned URL самого хранилища (S3 через `-flysystem`);
3. подписанный proxy-URL приложения (`-web`).

`url()` и `temporaryUrl()` отдают шаги 1 и 2 в сыром виде — для
инфраструктурного кода. Прикладной код и шаблоны не должны ветвиться на «а
публичное ли у нас хранилище» — ровно для этого и есть `urlFor()`.

### Группы и политики

Группа — это сценарий, а не папка. Правила приёма задаются один раз в `params`,
вместо повторной валидации перед каждым `add()`:

```php
// config/common/params.php
return [
    'rasuvaeff/yii3-filestorage' => [
        'defaultGroup' => 'common',
        'policies' => [
            'avatars' => [
                'allowedMimeTypes' => ['image/jpeg', 'image/png', 'image/webp'],
                'maxBytes' => 5_242_880,
                'maxPixels' => 40_000_000,
                'requireImageDimensions' => true,
            ],
            'documents' => [
                'allowedMimeTypes' => ['application/pdf'],
                'maxBytes' => 52_428_800,
            ],
            '*' => ['maxBytes' => 20_971_520],
        ],
        'delivery' => [
            '*' => ['allowDirectPublicUrl' => false, 'forceDownload' => true],
        ],
    ],
];
```

Загрузка, не прошедшая политику, бросает `PolicyViolationException` **до того,
как хранилище будет тронуто**, — на диск ничего не попадает.

### Параметры

| Ключ | По умолчанию | Смысл |
|---|---|---|
| `defaultGroup` | `common` | Группа, если `add()` вызван без неё |
| `maxInlineBytes` | 8 MiB | Ограничение для `content()` |
| `maxSpoolBytes` | 256 MiB | Ограничение на буферизацию неперематываемой загрузки |
| `integrityHashMaxBytes` | `0` | `0` оставляет `contentHash` пустым; положительное значение включает ограниченный SHA-256 |
| `defaultUrlTtl` | `PT1H` | Срок жизни, который `urlFor()` берёт по умолчанию |
| `extensionOverrides` | `[]` | Media type ⇒ расширение, поверх таблицы `symfony/mime` |
| `policies` | `['*' => …]` | Правила приёма на группу |
| `delivery` | `['*' => …]` | Правила выдачи на группу |

### Обработка сбоев

Каждый `add()` атомарен сам по себе: либо строка метаданных вместе с объектом,
либо ничего.

| Что упало | Что вы получите |
|---|---|
| Политика отвергла загрузку | `PolicyViolationException`; ничего не записано |
| Лимит байт перейдён во время копирования | `UploadTooLargeException`; частичный объект удалён |
| Сохранение метаданных упало после записи | `AddException`; объект удаляется best-effort |
| Удаление объекта упало после удаления строки | `RemoveException`; объект — сирота для `filestorage:gc` |

`addMany()` нет. Атомарная пачка невозможна ни на файловой системе, ни в
объектном хранилище, а метод с таким именем это обещал бы. Пройдите циклом по
`add()` и обработайте частичный сбой явно.

### Контракты дедупликации

`Storage` никогда не разделяет байты: каждый `add()` владеет своим уникальным
объектом — именно поэтому компенсация может безопасно удалить то, что только
что записала. Разделение по содержимому — другой жизненный цикл, он живёт в
`rasuvaeff/yii3-filestorage-db`. Но контракты, которые он координирует,
объявлены здесь, чтобы потребитель мог реализовать или подменить их без
зависимости от пакета БД.

| Тип | Роль |
|---|---|
| `Store\BlobLedgerInterface` | Кто владеет разделяемыми байтами и как долго. Reserve → publish → commit, а удаление только *планирует* blob |
| `Store\BlobId` | Физическое владение: один объект в одном хранилище. Не хеш содержимого — тот одинаков для разных хранилищ, групп и тенантов |
| `Store\BlobState` | `writing`, `active`, `pending_delete`, `deleting` — четыре состояния, между которыми происходят все сбои дедупликации |
| `Store\BlobToken` | Непрозрачный дескриптор, выданный реестром. По одному на притязание, чтобы упавший писатель освобождал только своё |
| `Store\BlobReservation` | Истекающее притязание писателя на время публикации байт. На одном blob их может быть несколько |
| `Store\BlobLease` | Эксклюзивное истекающее право на удаление. Не больше одного на blob; после истечения его можно перехватить — так восстанавливается упавший сборщик |
| `Store\BlobRecord` | Read-only снимок строки реестра для `gc`, `verify` и `stat` |
| `Exception\BlobBusyException` | Временная ошибка: blob удаляется, повторить после истечения lease |
| `Exception\LedgerException` | Не временная: резервация, файл или содержимое не совпадают с тем, что держит реестр |

Правило, на котором держится весь дизайн: **разделяемые байты никогда не
удаляются внутри запроса.** Последнее освобождение только помечает blob как
`pending_delete`; удаляет только сборщик, только под lease и только пока
счётчики ссылок и резерваций всё ещё нулевые в том же самом операторе, который
берёт lease.

### Тенантный scope

Два контракта, оба опциональные, оба по умолчанию не связаны:

| Интерфейс | Кто биндит | На что отвечает |
|---|---|---|
| `Repository\FileScopeProviderInterface` | ваше приложение | «какой тенант в этом запросе?» — из `rasuvaeff/yii3-tenancy`, сессии, поддомена или константы |
| `Repository\ScopedFileResolverInterface` | `rasuvaeff/yii3-filestorage-db` | «дай этот файл *в этом scope*» |

Второй существует потому, что у подписанной загрузки нет окружающего тенанта —
в этом и смысл подписи, — и соблазнительный «фикс» состоит в том, чтобы
отключить тенантный фильтр для скачиваний. Такой фикс — межтенантное чтение
любого файла, чей id утёк. Вместо этого scope едет внутри подписанного токена
(`SignedPayload::$scopeId`) и сверяется вторым предикатом.

## Замороженные решения

Две вещи нельзя изменить после первого релиза, поэтому они решены сразу.

**Раскладка путей — директория на файл.** Каждый генератор отдаёт
`<…>/<key>/original.<ext>`, никогда не голое имя файла, а `delete()` удаляет
именно *директорию*. Это то, что позволяет превью лежать в `<key>/thumb.webp`
без изменения схемы, и то, что не даёт производным утекать при удалении файла.
Рендер описывается через `DerivativeDescriptor` — это *именованный* пресет, а не
свободные размеры: свободные параметры превращают одну загрузку в неограниченное
множество адресуемых производных. Результат записи хранилище отдаёт как
`DerivativeObject`.
Расширение берётся из **определённого** media type через таблицу
`symfony/mime`; имя файла от клиента в нём не участвует, а нераспознанный тип
даёт `original.bin`.

**Формат токена.** `v1.<key-id>.<expires>.<base64url payload>.<base64url hmac>`,
HMAC-SHA256 по всему, что стоит до подписи. Payload — канонический JSON
`{fileId, variant, scopeId}`: вариант находится внутри подписи, поэтому токен,
выпущенный для отредактированного или уменьшенного рендера, нельзя переиграть
на оригинал. Идентификатор ключа входит в аутентифицированный конверт, поэтому
ротация не ломает ещё живые URL, пока предыдущий ключ остаётся в связке.

## Расширение

`StorageInterface` — обычный интерфейс с `final`-реализацией, поэтому квоты,
метрики, трассировка и антивирусная проверка — это **декораторы, которые вы
пишете**, а не пакеты, которые вы ставите:

```php
final readonly class QuotaStorage implements StorageInterface
{
    public function __construct(private StorageInterface $inner, private Quotas $quotas) {}

    public function add(Upload $upload, ?string $groupName = null, /* … */): File
    {
        $this->quotas->assertRoom($upload->size());

        return $this->inner->add($upload, $groupName, /* … */);
    }

    // … остальное делегировать
}
```

Забиндите свой декоратор на `StorageInterface` в слое приложения. Форкать ядро
ради этого не нужно.

## Тестирование своего кода

Тестовые двойники лежат в `src/`, а не в `tests/`, — чтобы они действительно
устанавливались:

| Класс | Зачем |
|---|---|
| `Test\InMemoryStore` | Хранилище без диска. Реализует только базовый контракт и maintenance — **не** URL и не Range, чтобы можно было проверить поведение кода, когда хранилище не умеет presign |
| `Test\MemoryRepository` | Метаданные в массиве |
| `Test\MemoryBlobLedger` | Машина состояний дедупликации в массиве: оживление, истечение резерваций, перехват lease, условное завершение. Чего он не воспроизводит — конкурентности: у PHP-массива нет уровней изоляции, поэтому доказывать корректность гонки двух писателей всё равно нужно на БД |

Двойника часов здесь сознательно нет: `InMemoryStore` и `Storage` принимают
любые PSR-20-часы, а в Yii-приложении уже есть
`Yiisoft\Test\Support\Clock\StaticClock`. Свой второй был бы дублированием,
а не удобством.

```php
$store = new InMemoryStore('test', $streamFactory, new StaticClock($now));
$storage = new Storage(
    stores: new StoreRegistry([$store]),
    repository: new MemoryRepository(),
    // …
);

$file = $storage->add(Upload::fromStream($stream, 'a.txt', $streamFactory));

Assert::same($store->writeCount(), 1);
Assert::same($store->bytesAt($file->relativePath), 'hello');
```

## Безопасность

| Граница | Правило |
|---|---|
| Media type | Авторитетен только вывод `finfo`. Тип от клиента сохраняется для диагностики и никогда не попадает ни в политику, ни в путь, ни в заголовок ответа |
| Пути | Всегда генерируются, никогда не берутся из запроса. `StoredObjectId` отвергает `..`, NUL, обратные слеши и абсолютные пути; локальные хранилища перепроверяют вложенность через `realpath()` после разрешения, поэтому подложенный симлинк не даст прочитать что-то вне корня |
| Исходное имя файла | Только метаданные. В путь оно не попадает, а CR/LF/NUL вырезаются до того, как оно дойдёт до заголовка |
| Размер на входе | Неперематываемая загрузка буферизуется с конечным лимитом; хранилища применяют `maxBytes` группы *во время копирования* и удаляют частичный вывод |
| Decompression bomb | `maxPixels` проверяется по заголовку изображения через `getimagesizefromstring()`. Пиксели не декодируются никогда |
| Прямые публичные URL | Выключены по умолчанию. `filestorage:check` **падает**, когда группа сочетает их с разрешительным списком или с активным контентом |
| Подписанные URL | HMAC-SHA256 по версии, id ключа, сроку и каноническому payload; строгие проверки длины и схемы; `hash_equals()`; ротация через связку ключей; ключ короче 32 байт — ошибка конфигурации |
| Дедупликация | Владение — это физический `BlobId`, а не счётчик по хешу: хеш есть идентичность содержимого и ничего не говорит о том, кому принадлежат байты |

То, что `finfo` распознал `image/svg+xml`, ничего не говорит о безопасности
инлайновой отдачи: SVG с вашего origin — это примитив для stored XSS. Держите
SVG вне групп, которым разрешены прямые публичные URL, и дайте `-web`
принудить скачивание.

Загруженные изображения сохраняют EXIF, а там на большинстве телефонных фото
есть GPS-координаты. Никто не ожидает, что загрузка аватара опубликует его
домашний адрес, — вырезайте метаданные или перекодируйте, если файлы будут
отдаваться публично.

## Консоль

| Команда | Что делает |
|---|---|
| `filestorage:check` | Показывает проводку, возможности каждого хранилища и правила каждой группы; падает на небезопасном сочетании выдачи |
| `filestorage:stat` | Количество и размер по группам плюс то, сколько сэкономило разделение объектов (физические цифры не выводятся при аренде) |
| `filestorage:verify` | Показывает строки, у которых пропал объект; `--deep` перечитывает каждый и сверяет хэш |
| `filestorage:backfill-hash` | Проставляет `contentHash` строкам, записанным до включения хэширования |
| `filestorage:gc` | Собирает неиспользуемые общие блобы, а с `--orphans` — объекты, на которые не ссылается ни одна строка |

`gc`, `backfill-hash` и `deduplicate` из пакета `-db` **по умолчанию только
отчитываются и действуют лишь с `--apply`**. Команда, которая удаляет с первого
запуска, рано или поздно запускается не на той базе. У `verify` `--apply` нет
вовсе: что делать с пропавшим объектом — восстановить, перезалить, удалить
строку — команда решать за вас не должна.

Все четыре идут по id страницами и печатают последний достигнутый, так что
таблица, слишком большая для одного прогона, обрабатывается серией ограниченных:

```bash
./yii filestorage:verify --limit=10000
# Last id: 019603f2-…
./yii filestorage:verify --limit=10000 --after=019603f2-…
```

`gc`, `verify`, `backfill-hash` и `stat` требуют бэкенда, реализующего
`MaintenanceRepositoryInterface` (`-db` реализует). `gc` вдобавок собирает общие
блобы только когда привязан `BlobLedgerInterface`; без него он всё равно
подметает orphans. С `--apply` это **единственное** место в семействе, которое
удаляет байты, нужные другому запросу, и делает это под эксклюзивной аренду с
истечением — см. раздел про дедупликацию в `-db`.

После включения дедупликации порядок важен: `deduplicate --apply` перенаправляет
строки, а объекты, на которые они указывали, становятся orphans — их забирает
`gc --orphans --apply`.

### Аренда и подметание orphans

**`gc --orphans` отказывается работать, когда привязан
`FileScopeProviderInterface`**, и это осознанный отказ, а не ограничение,
которое надо обойти. Объект — orphan, когда на него не ссылается *ни одна*
строка нигде. Множество ссылок берётся из репозитория, который фильтрует по
текущему арендатору; перечисление объектов физическое и не фильтрует ни по
чему. Сравнение этих двух при аренде объявляет orphan'ами все объекты остальных
арендаторов, а `--apply` их удаляет. Арендатора, «от имени» которого это можно
запустить, не существует: строки одного арендатора не доказывают, что объект
никому не нужен.

Запускайте подметание из обслуживающей точки входа, где провайдер областей не
привязан и репозиторий видит все строки. Сбор блобов это не затрагивает: реестр
ключуется физической идентичностью, а не арендатором, поэтому обычный
`gc --apply` работает в установке любого вида.

`filestorage:stat` читает через тот же ограниченный репозиторий и делится по той
же линии, но мягче. Количества и суммы байт — *логические*: они описывают
строки, а строки одного арендатора и есть верный ответ на вопрос «сколько у
этого арендатора», поэтому они по-прежнему печатаются, под заголовком
`Group (current scope)`. «Distinct objects» и экономия от разделения —
*физические*: они утверждают, сколько объектов существует и сколько строк
указывает на каждый, а обход с фильтром по арендатору не видит строк других
областей, указывающих на те же объекты. Он показал бы больше различных объектов,
чем есть, и меньше разделения, чем на самом деле, — поэтому при привязанном
провайдере областей **обе цифры не выводятся** вместо того, чтобы быть
прикинутыми. Физические цифры получаются запуском `stat` с непривязанным
провайдером.

## Примеры

Исполняемые скрипты — в [examples/](examples/README.md).

## Разработка

```bash
make build          # validate + normalize + require-checker + cs + psalm + test
make cs-fix
make psalm
make test
make test-coverage
make mutation
make release-check
```

PHP на хосте нет — всё гоняется в Docker-образе `composer:2`.

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).

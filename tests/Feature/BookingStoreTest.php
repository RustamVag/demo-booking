<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingStoreTest extends TestCase
{
    use RefreshDatabase;

    private const string DAY = '2030-06-10';

    private User $user;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->room = Room::factory()->for(Office::factory()->create())->create();
    }

    /**
     * Проверяет, что бронь создаётся с автором из аутентификации и корректными room_id и user_id в ответе и БД.
     */
    public function test_creates_booking_with_authenticated_user_as_author(): void
    {
        $this->postBooking($this->bookingPayload(self::isoAt('10:00'), self::isoAt('11:00')))
            ->assertCreated()
            ->assertJsonPath('user_id', $this->user->id)
            ->assertJsonPath('room_id', $this->room->id);

        $this->assertDatabaseHas('bookings', [
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'starts_at' => self::DAY.' 07:00:00',
            'ends_at' => self::DAY.' 08:00:00',
        ]);
    }

    /**
     * Проверяет, что время с любым смещением (Z, +03:00, +06:00, -05:00) сохраняется в БД в UTC.
     */
    #[DataProvider('clientOffsetProvider')]
    public function test_stores_time_in_utc_regardless_of_client_offset(string $startsAt, string $endsAt): void
    {
        $this->postBooking($this->bookingPayload($startsAt, $endsAt))->assertCreated();

        $this->assertDatabaseHas('bookings', [
            'starts_at' => self::DAY.' 07:00:00',
            'ends_at' => self::DAY.' 08:00:00',
        ]);
    }

    public static function clientOffsetProvider(): array
    {
        return [
            'UTC (Z)' => [self::isoAt('07:00', 'Z'), self::isoAt('08:00', 'Z')],
            'смещение +03:00' => [self::isoAt('10:00', '+03:00'), self::isoAt('11:00', '+03:00')],
            'смещение +06:00' => [self::isoAt('13:00', '+06:00'), self::isoAt('14:00', '+06:00')],
            'смещение -05:00' => [self::isoAt('02:00', '-05:00'), self::isoAt('03:00', '-05:00')],
        ];
    }

    /**
     * Проверяет, что встречи впритык в одной комнате разрешены.
     */
    public function test_allows_back_to_back_bookings_in_same_room(): void
    {
        $this->createBooking(self::isoAt('10:00'), self::isoAt('11:00'));

        $this->postBooking($this->bookingPayload(self::isoAt('11:00'), self::isoAt('12:00')))
            ->assertCreated();

        $this->postBooking($this->bookingPayload(self::isoAt('09:00'), self::isoAt('10:00')))
            ->assertCreated();

        $this->assertDatabaseCount('bookings', 3);
    }

    /**
     * Проверяет, что любое пересечение с существующей бронью в той же комнате возвращает 409 и не создаёт запись.
     */
    #[DataProvider('overlappingTimesProvider')]
    public function test_rejects_booking_overlapping_existing_one_in_same_room(string $startsAt, string $endsAt): void
    {
        $this->createBooking(self::isoAt('10:00'), self::isoAt('11:00'));

        $this->postBooking($this->bookingPayload(self::isoAt($startsAt), self::isoAt($endsAt)))
            ->assertConflict()
            ->assertJsonPath('error', 'Комната занята');

        $this->assertDatabaseCount('bookings', 1);
    }

    public static function overlappingTimesProvider(): array
    {
        return [
            'начинается внутри существующей' => ['10:30', '11:30'],
            'заканчивается внутри существующей' => ['09:00', '10:30'],
            'полностью внутри существующей' => ['10:15', '10:45'],
            'полностью покрывает существующую' => ['09:00', '12:00'],
            'совпадает с существующей' => ['10:00', '11:00'],
        ];
    }

    /**
     * Проверяет, что бронь на то же время в другой комнате того же офиса не считается конфликтом.
     */
    public function test_allows_same_time_in_another_room(): void
    {
        $this->createBooking(self::isoAt('10:00'), self::isoAt('11:00'));

        $otherRoom = Room::factory()->for($this->room->office)->create();

        $this->postBooking($this->bookingPayload(self::isoAt('10:00'), self::isoAt('11:00'), $otherRoom->id))
            ->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
    }

    /**
     * Проверяет, что длительность ровно 15 минут и ровно 4 часа являются допустимыми границами.
     */
    #[DataProvider('validDurationProvider')]
    public function test_accepts_duration_from_15_minutes_to_4_hours(string $startsAt, string $endsAt): void
    {
        $this->postBooking($this->bookingPayload($startsAt, $endsAt))->assertCreated();

        $this->assertDatabaseCount('bookings', 1);
    }

    public static function validDurationProvider(): array
    {
        return [
            'минимум 15 минут' => [self::isoAt('10:00'), self::isoAt('10:15')],
            'максимум 4 часа' => [self::isoAt('10:00'), self::isoAt('14:00')],
        ];
    }

    /**
     * Проверяет, что нулевая, отрицательная, короче 15 минут и длиннее 4 часов длительности отклоняются с 422.
     */
    #[DataProvider('invalidDurationProvider')]
    public function test_rejects_invalid_duration(string $startsAt, string $endsAt, string $errorField): void
    {
        $this->postBooking($this->bookingPayload($startsAt, $endsAt))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$errorField]);

        $this->assertDatabaseCount('bookings', 0);
    }

    public static function invalidDurationProvider(): array
    {
        return [
            'конец совпадает с началом' => [self::isoAt('10:00'), self::isoAt('10:00'), 'ends_at'],
            'конец раньше начала' => [self::isoAt('11:00'), self::isoAt('10:00'), 'ends_at'],
            'короче 15 минут' => [self::isoAt('10:00'), self::isoAt('10:10'), 'starts_at'],
            'дольше 4 часов' => [self::isoAt('10:00'), self::isoAt('14:15'), 'starts_at'],
        ];
    }

    /**
     * Проверяет, что начало или конец не на сетке 15 минут либо с ненулевыми секундами отклоняются с 422.
     */
    #[DataProvider('misalignedTimeProvider')]
    public function test_rejects_times_not_aligned_to_15_minute_grid(string $startsAt, string $endsAt): void
    {
        $this->postBooking($this->bookingPayload($startsAt, $endsAt))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);

        $this->assertDatabaseCount('bookings', 0);
    }

    public static function misalignedTimeProvider(): array
    {
        return [
            'начало не кратно 15 минутам' => [self::isoAt('10:07'), self::isoAt('11:07')],
            'конец не кратен 15 минутам' => [self::isoAt('10:00'), self::isoAt('10:20')],
            'указаны секунды' => [self::DAY.'T10:00:30+03:00', self::DAY.'T11:00:00+03:00'],
        ];
    }

    /**
     * Проверяет, что бронь в границах 09:00–20:00 по местному времени офиса (Москва, Новосибирск) создаётся.
     */
    #[DataProvider('validWorkingHoursProvider')]
    public function test_accepts_booking_inside_office_working_hours(string $timezone, string $startsAt, string $endsAt): void
    {
        $room = $this->roomInOfficeTimezone($timezone);

        $this->postBooking($this->bookingPayload($startsAt, $endsAt, $room->id))->assertCreated();

        $this->assertDatabaseCount('bookings', 1);
    }

    public static function validWorkingHoursProvider(): array
    {
        return [
            'Москва: начало ровно в 09:00' => ['Europe/Moscow', self::isoAt('09:00'), self::isoAt('10:00')],
            'Москва: конец ровно в 20:00' => ['Europe/Moscow', self::isoAt('19:00'), self::isoAt('20:00')],
            'Новосибирск: начало ровно в 09:00' => ['Asia/Novosibirsk', '2030-06-10T02:00:00Z', '2030-06-10T02:15:00Z'],
            'Новосибирск: конец ровно в 20:00' => ['Asia/Novosibirsk', '2030-06-10T12:45:00Z', '2030-06-10T13:00:00Z'],
        ];
    }

    /**
     * Проверяет, что бронь, выходящая за 09:00–20:00 по времени офиса комнаты, отклоняется с 422.
     */
    #[DataProvider('invalidWorkingHoursProvider')]
    public function test_rejects_booking_outside_office_working_hours(string $timezone, string $startsAt, string $endsAt): void
    {
        $room = $this->roomInOfficeTimezone($timezone);

        $this->postBooking($this->bookingPayload($startsAt, $endsAt, $room->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);

        $this->assertDatabaseCount('bookings', 0);
    }

    public static function invalidWorkingHoursProvider(): array
    {
        return [
            'Москва: начало в 08:45' => ['Europe/Moscow', self::isoAt('08:45'), self::isoAt('09:30')],
            'Москва: конец в 20:15' => ['Europe/Moscow', self::isoAt('19:45'), self::isoAt('20:15')],
            'Москва: начало в 20:00' => ['Europe/Moscow', self::isoAt('20:00'), self::isoAt('20:30')],
            'Москва: 19:30–20:30 по местному, клиент шлёт UTC' => ['Europe/Moscow', '2030-06-10T16:30:00Z', '2030-06-10T17:30:00Z'],
            'Новосибирск: начало в 08:45 по местному' => ['Asia/Novosibirsk', '2030-06-10T01:45:00Z', '2030-06-10T02:30:00Z'],
            'Новосибирск: конец в 20:30 по местному' => ['Asia/Novosibirsk', '2030-06-10T12:30:00Z', '2030-06-10T13:30:00Z'],
        ];
    }

    /**
     * Проверяет, что бронирование на любые даты (прошлое и далёкое будущее) не ограничивается.
     */
    public function test_does_not_restrict_booking_dates(): void
    {
        $this->postBooking($this->bookingPayload('2020-01-15T10:00:00+03:00', '2020-01-15T11:00:00+03:00'))
            ->assertCreated();

        $this->postBooking($this->bookingPayload('2040-12-31T10:00:00+03:00', '2040-12-31T11:00:00+03:00'))
            ->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
    }

    /**
     * Проверяет, что некорректные или отсутствующие поля запроса возвращают 422 с ошибкой по нужному полю.
     */
    #[DataProvider('invalidPayloadProvider')]
    public function test_returns_validation_errors_for_invalid_payload(array $overrides, string $errorField): void
    {
        $payload = array_merge(
            $this->bookingPayload(self::isoAt('10:00'), self::isoAt('11:00')),
            $overrides,
        );

        $this->postBooking($payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$errorField]);

        $this->assertDatabaseCount('bookings', 0);
    }

    public static function invalidPayloadProvider(): array
    {
        return [
            'без room_id' => [['room_id' => null], 'room_id'],
            'несуществующая комната' => [['room_id' => 999999], 'room_id'],
            'без title' => [['title' => null], 'title'],
            'title длиннее 255 символов' => [['title' => str_repeat('a', 256)], 'title'],
            'без starts_at' => [['starts_at' => null], 'starts_at'],
            'starts_at не дата' => [['starts_at' => 'не дата'], 'starts_at'],
        ];
    }

    /**
     * Время в ISO 8601 для дня тестов. По умолчанию смещение московское (+03:00).
     */
    private static function isoAt(string $time, string $offset = '+03:00'): string
    {
        return self::DAY.'T'.$time.':00'.$offset;
    }

    /**
     * @return array{room_id: int, title: string, starts_at: string, ends_at: string}
     */
    private function bookingPayload(string $startsAt, string $endsAt, ?int $roomId = null): array
    {
        return [
            'room_id' => $roomId ?? $this->room->id,
            'title' => 'Планерка',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ];
    }

    private function postBooking(array $payload): TestResponse
    {
        Sanctum::actingAs($this->user);

        return $this->postJson('/api/bookings', $payload);
    }

    private function createBooking(string $startsAt, string $endsAt, ?Room $room = null): Booking
    {
        return Booking::factory()
            ->for($room ?? $this->room)
            ->for(User::factory())
            ->create([
                'starts_at' => Carbon::parse($startsAt)->utc(),
                'ends_at' => Carbon::parse($endsAt)->utc(),
            ]);
    }

    private function roomInOfficeTimezone(string $timezone): Room
    {
        return Room::factory()
            ->for(Office::factory()->create(['timezone' => $timezone]))
            ->create();
    }
}

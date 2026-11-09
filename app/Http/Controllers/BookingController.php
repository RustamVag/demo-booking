<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    /**
     * @throws ValidationException
     */
    public function store(StoreBookingRequest $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validated();

        $room = Room::with('office')->findOrFail($data['room_id']);
        $timezone = $room->office->timezone;

        $start = Carbon::parse($data['starts_at'])->setTimezone($timezone);
        $end   = Carbon::parse($data['ends_at'])->setTimezone($timezone);

        if ($end->lte($start)) {
            throw ValidationException::withMessages([
                'ends_at' => 'Время окончания должно быть позже времени начала.',
            ]);
        }

        $duration = (int) $start->diffInMinutes($end);

        $validGrid = $start->second === 0 && $end->second === 0
            && $start->minute % 15 === 0 && $end->minute % 15 === 0;

        if (! $validGrid || $duration < 15 || $duration > 240) {
            throw ValidationException::withMessages([
                'starts_at' => 'Длительность — от 15 минут до 4 часов с шагом 15 минут.',
            ]);
        }

        $opensAt  = $start->copy()->setTime(9, 0);
        $closesAt = $start->copy()->setTime(20, 0);

        if ($start->lt($opensAt) || $end->gt($closesAt) || ! $start->isSameDay($end)) {
            throw ValidationException::withMessages([
                'starts_at' => 'Бронь должна целиком попадать в рабочее время 09:00–20:00 по времени офиса.',
            ]);
        }

        $startUtc = $start->copy()->utc();
        $endUtc   = $end->copy()->utc();

        $booking = DB::transaction(function () use ($request, $data, $room, $startUtc, $endUtc) {
            Room::query()->whereKey($room->id)->lockForUpdate()->first();

            $isBooked = Booking::query()
                ->where('room_id', $room->id)
                ->where('starts_at', '<', $endUtc)
                ->where('ends_at', '>', $startUtc)
                ->exists();

            if ($isBooked) {
                abort(response()->json(['error' => 'Комната занята'], 409));
            }

            return Booking::create([
                'room_id'   => $room->id,
                'user_id'   => $request->user()->id,
                'title'     => $data['title'],
                'starts_at' => $startUtc,
                'ends_at'   => $endUtc,
            ]);
        });

        Log::channel('bookings')->info('Booking created', ['booking_id' => $booking->id]);

        return response()->json($booking, 201);
    }

    public function destroy(Request $request, string $id): \Illuminate\Http\Response
    {
        $booking = $request->user()->bookings()->findOrFail($id);

        $booking->delete();

        return response()->noContent();
    }

    public function today(Room $room)
    {
        return $room->bookings()
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at . ' – ' . $b->ends_at,
                'author' => $b->user->name,
            ]);
    }
}

<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Public\PublicAvailabilityRequest;
use App\Http\Requests\Api\Public\PublicBookAppointmentRequest;
use App\Http\Requests\ListSalonsRequest;
use App\Http\Requests\NearbySalonsRequest;
use App\Http\Resources\PublicAppointmentResource;
use App\Http\Resources\PublicBranchResource;
use App\Http\Resources\PublicSalonResource;
use App\Http\Resources\PublicServiceResource;
use App\Services\PublicBooking\PublicBookAppointmentService;
use App\Services\PublicBooking\PublicBookingAvailabilityService;
use App\Services\PublicBooking\PublicSalonCatalogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * @group Public Booking
 *
 * Public browsing, availability, and booking APIs.
 */
class PublicBookingController extends Controller
{
    public function __construct(
        private readonly PublicSalonCatalogService $salonCatalog,
        private readonly PublicBookingAvailabilityService $availabilitySlots,
        private readonly PublicBookAppointmentService $bookAppointment,
    ) {}

    /**
     * List Salons
     *
     * @unauthenticated
     */
    public function salons(ListSalonsRequest $request): JsonResponse
    {
        $result = $this->salonCatalog->paginateSalonListing($request->validated());

        return $this->salonPaginatorJsonResponse($result);
    }

    /**
     * Nearby Salons
     *
     * @unauthenticated
     */
    public function nearbySalons(NearbySalonsRequest $request): JsonResponse
    {
        $result = $this->salonCatalog->paginateNearbySalonListing($request->validated());

        return $this->salonPaginatorJsonResponse($result);
    }

    /**
     * Salon Details
     *
     * @unauthenticated
     */
    public function salon(string $slug): JsonResponse
    {
        $data = $this->salonCatalog->findSalonDetailBySlug($slug);

        if (! $data) {
            return $this->notFound('Salon not found');
        }

        return $this->success([
            'salon' => (new PublicSalonResource($data['tenant']))->resolve(),
            'branches' => PublicBranchResource::collection($data['branches'])->resolve(),
            'services' => PublicServiceResource::collection($data['services'])->resolve(),
        ]);
    }

    /**
     * Check Availability
     *
     * @unauthenticated
     */
    public function availability(PublicAvailabilityRequest $request): JsonResponse
    {
        $slots = $this->availabilitySlots->computeFreeSlots($request->validated());

        return $this->success(['slots' => $slots]);
    }

    /**
     * Book Appointment
     *
     * @unauthenticated
     */
    public function book(PublicBookAppointmentRequest $request): JsonResponse
    {
        try {
            $appointment = $this->bookAppointment->book($request, $request->validated());

            return $this->created(
                ['appointment' => (new PublicAppointmentResource($appointment))->resolve()],
                'Appointment booked successfully'
            );
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable) {
            return $this->error('Booking failed. Please try again.');
        }
    }

    /**
     * @param  LengthAwarePaginator<int, \App\Models\Tenant>  $result
     */
    private function salonPaginatorJsonResponse(LengthAwarePaginator $result): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => PublicSalonResource::collection($result)->resolve(),
            'meta' => [
                'current_page' => $result->currentPage(),
                'per_page' => $result->perPage(),
                'total' => $result->total(),
                'last_page' => $result->lastPage(),
                'from' => $result->firstItem(),
                'to' => $result->lastItem(),
            ],
            'links' => [
                'first' => $result->url(1),
                'last' => $result->url($result->lastPage()),
                'prev' => $result->previousPageUrl(),
                'next' => $result->nextPageUrl(),
            ],
        ]);
    }
}

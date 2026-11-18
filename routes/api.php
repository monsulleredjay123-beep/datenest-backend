<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminPublicPlaceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EstablishmentController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OwnerEstablishmentController;
use App\Http\Controllers\OwnerReservationController;
use App\Http\Controllers\PreferenceController;
use App\Http\Controllers\PublicPlaceController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\ReviewController;
use Illuminate\Support\Facades\Route;

// PUBLIC 
Route::post('/register/otp', [AuthController::class, 'sendRegisterOtp'])->middleware('throttle:6,1');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/password/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:6,1');
Route::post('/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');
Route::get('/public/places', [PublicPlaceController::class, 'index'])->middleware('throttle:60,1');

// NAKA-LOGIN (tanang role; i-check pud kung active ang account)
Route::middleware(['auth:sanctum', 'role:customer,establishment_admin,admin'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Notifications (bell): tanang role. Ang 'read-all' kinahanglan una sa '{notification}'.
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read']);

    // Discovery (ang 'search' kinahanglan una sa '{establishment}')
    Route::get('/establishments/search', [EstablishmentController::class, 'search']);
    Route::get('/establishments/{establishment}', [EstablishmentController::class, 'show']);
    Route::get('/establishments/{establishment}/reviews', [EstablishmentController::class, 'reviews']);

    // Preferences (dynamic): tanang role makakita; owner ug admin ra ang pwede mag-add
    Route::get('/preferences', [PreferenceController::class, 'index']);
    Route::post('/preferences', [PreferenceController::class, 'store'])
        ->middleware(['role:establishment_admin,admin', 'throttle:30,1']);

    // ACCOUNT 
    // Password: customer ug owner (ang admin dili)
    Route::middleware('role:customer,establishment_admin')->prefix('account')->group(function () {
        Route::post('/otp', [AccountController::class, 'requestOtp'])->middleware('throttle:6,1');
        Route::put('/password', [AccountController::class, 'changePassword']);
    });
    // Delete sa kaugalingong account: customer ug owner ra
    Route::middleware('role:customer,establishment_admin')->prefix('account')->group(function () {
        Route::post('/delete', [AccountController::class, 'destroy']);
    });

    //  CUSTOMER 
    Route::middleware('role:customer')->group(function () {
        Route::post('/reservations', [ReservationController::class, 'store']);
        Route::get('/reservations/mine', [ReservationController::class, 'mine']);
        Route::post('/reservations/{reservation}/pay', [ReservationController::class, 'pay']);
        Route::post('/reservations/{reservation}/review', [ReviewController::class, 'store']);

        // Feedback: bukas sa tanan nga customer (dili na kinahanglan ug completed nga reservation). Usa ka feedback kada lugar.
        Route::get('/establishments/{establishment}/reviews/mine', [ReviewController::class, 'mine']);
        Route::put('/establishments/{establishment}/reviews/mine', [ReviewController::class, 'save'])->middleware('throttle:20,1');
        Route::delete('/establishments/{establishment}/reviews/mine', [ReviewController::class, 'destroy']);
    });

    // ESTABLISHMENT ADMIN (OWNER) 
    Route::middleware('role:establishment_admin')->group(function () {
        Route::get('/owner/establishments', [OwnerEstablishmentController::class, 'index']);
        Route::post('/owner/establishment', [OwnerEstablishmentController::class, 'store']);
        Route::put('/owner/establishment/{establishment}', [OwnerEstablishmentController::class, 'update']);
        // Ads: LIBRE na. Ang contract magsugod human ma-approve sa admin.
        // Renewal human ma-expire: i-update ang business permit para ma-review sa admin
        Route::post('/owner/establishment/{establishment}/renewal', [OwnerEstablishmentController::class, 'requestRenewal']);
        // Samtang gi-review: i-request o usbon ang gusto nga term (base sa expiry sa permit)
        Route::put('/owner/establishment/{establishment}/term-request', [OwnerEstablishmentController::class, 'setTermRequest']);

        Route::get('/owner/reservations', [OwnerReservationController::class, 'index']);
        Route::patch('/reservations/{reservation}/approve', [OwnerReservationController::class, 'approve']);
        Route::patch('/reservations/{reservation}/reject', [OwnerReservationController::class, 'reject']);
        Route::patch('/reservations/{reservation}/complete', [OwnerReservationController::class, 'complete']);
    });

    // SYSTEM ADMIN 
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::get('/users', [AdminController::class, 'users']);
        Route::patch('/users/{user}/toggle', [AdminController::class, 'toggleUser']);
        Route::delete('/users/{user}', [AdminController::class, 'destroyUser']);
        Route::get('/establishments', [AdminController::class, 'establishments']);
        Route::patch('/establishments/{establishment}/toggle', [AdminController::class, 'toggleEstablishment']);

        // Registration review (document check)
        Route::get('/establishments/{establishment}/review', [AdminController::class, 'reviewEstablishment']);
        Route::post('/establishments/{establishment}/approve', [AdminController::class, 'approveEstablishment']);
        Route::post('/establishments/{establishment}/reject', [AdminController::class, 'rejectEstablishment']);

        // Public places (park, beach, viewpoint...): ang admin ra ang mo-add. Walay approval, walay contract.
        Route::get('/public-places', [AdminPublicPlaceController::class, 'index']);
        Route::post('/public-places', [AdminPublicPlaceController::class, 'store']);
        Route::put('/public-places/{establishment}', [AdminPublicPlaceController::class, 'update']);
        Route::patch('/public-places/{establishment}/toggle', [AdminPublicPlaceController::class, 'toggle']);
        Route::delete('/public-places/{establishment}', [AdminPublicPlaceController::class, 'destroy']);
    });
});
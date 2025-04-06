# 📦 FMV Backend Project

This repository contains the backend for the FMV application built with **Laravel**. It includes a Laravel 10 REST API setup using **Laravel Passport** for authentication.

---

## 🛠️ Prerequisites

Ensure the following are installed on your local machine:

- PHP >= 7.4 (>= 8.0 for Laravel 10)
- Composer
- Node.js & npm
- MySQL
- XAMPP (optional for running Apache and MySQL locally)

---

## 🚀 Getting Started

### 1. Clone the Repository

```sh
git clone <your-repo-url>
cd <your-project-directory>
```

### 2. Install PHP Dependencies

```sh
composer install
```

### 3. Configure Environment

```sh
cp .env.example .env
```

Edit `.env` and update the database and app URL settings accordingly.

### 4. Generate App Key

```sh
php artisan key:generate
```

### 5. Run Migrations

```sh
php artisan migrate
```

### 6. Install Laravel Passport

```sh
composer require laravel/passport
php artisan passport:install
```

If you encounter an error regarding the `personal access client`, run:

```sh
php artisan passport:client --personal
```

---

## 🔐 Passport Authentication Setup

Update `config/auth.php` to use Passport:

```php
'guards' => [
    'web' => ['driver' => 'session', 'provider' => 'users'],
    'api' => ['driver' => 'passport', 'provider' => 'users'],
],
```

---

## 📄 Additional Setup

### Models

#### `app/Models/User.php`

```php
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
    ...
}
```

---

### Routes

#### `routes/api.php`

```php
use App\Http\Controllers\API\RegisterController;
use App\Http\Controllers\API\ProductController;

Route::post('register', [RegisterController::class, 'register']);
Route::post('login', [RegisterController::class, 'login']);

Route::middleware('auth:api')->group(function () {
    Route::resource('products', ProductController::class);
});
```

---

### BaseController

Handles common success and error responses.

#### `app/Http/Controllers/API/BaseController.php`

```php
class BaseController extends Controller
{
    public function sendResponse($result, $message) {
        return response()->json(['success' => true, 'data' => $result, 'message' => $message], 200);
    }

    public function sendError($error, $errorMessages = [], $code = 404) {
        return response()->json(['success' => false, 'message' => $error, 'data' => $errorMessages], $code);
    }
}
```

---

### RegisterController

Handles registration and login.

#### `app/Http/Controllers/API/RegisterController.php`

```php
public function register(Request $request)
{
    ...
    $user = User::create($input);
    $success['token'] = $user->createToken('MyApp')->accessToken;
    ...
}
public function login(Request $request)
{
    if (Auth::attempt(...)) {
        ...
        return $this->sendResponse($success, 'User login successfully.');
    } else {
        return $this->sendError('Unauthorised.', ['error' => 'Unauthorised']);
    }
}
```

---

## ⚙️ Running the Application

1. Start **Apache** and **MySQL** using XAMPP or your preferred method.
2. Run the Laravel server using:

```sh
php artisan serve
```

Or using your IP and port:

```sh
php artisan serve --host=192.168.1.6 --port=3000
```

> Ensure your `.env` has this set:
> ```env
> APP_URL=http://192.168.1.6:3000
> ```

---

## 🌐 Database Configuration

Ensure `.env` has correct DB settings:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=root
DB_PASSWORD=
```

---

## ✅ Troubleshooting

- If Passport complains about personal clients, re-run:

```sh
php artisan passport:client --personal
```

---

## ❤️ Acknowledgments

- [Laravel](https://laravel.com) Framework
- Community Contributors

---

## 🧠 Laravel Learning Resources

- [Laravel Documentation](https://laravel.com/docs)
- [Laravel Bootcamp](https://bootcamp.laravel.com)
- [Laracasts](https://laracasts.com)

---

## 📝 License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

---

![Laravel Logo](https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg)


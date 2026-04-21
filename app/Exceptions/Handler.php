<?php

namespace App\Exceptions;

use App\Mail\ExceptionOccured;
<<<<<<< HEAD
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Mail;
use Throwable;
=======
use Exception;
use Illuminate\Auth\AuthenticationException;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Mail;
use Symfony\Component\Debug\Exception\FlattenException;
use Symfony\Component\Debug\ExceptionHandler as SymfonyExceptionHandler;
>>>>>>> 19f5e4d41d134205432345c7270c1d029cbe786e

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that should not be reported.
     *
     * @var array
     */
    protected $dontReport = [
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Auth\Access\AuthorizationException::class,
        \Symfony\Component\HttpKernel\Exception\HttpException::class,
        \Illuminate\Database\Eloquent\ModelNotFoundException::class,
        \Illuminate\Session\TokenMismatchException::class,
        \Illuminate\Validation\ValidationException::class,
    ];

    /**
     * Report or log an exception.
     *
     * This is a great spot to send exceptions to Sentry, Bugsnag, etc.
     *
     * @param  \Exception  $exception
     * @return void
     */
<<<<<<< HEAD
    public function report(Throwable $exception)
    {
        if ($this->shouldReport($exception)) {
            if (config('app.env') == 'demo') {
                $this->sendEmail($exception);
=======
    public function report(Exception $exception)
    {
        if ($this->shouldReport($exception)) {
            if (config('app.env') == 'demo') {
                $this->sendEmail($exception); // sends an email in demo server
>>>>>>> 19f5e4d41d134205432345c7270c1d029cbe786e
            }
        }

        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Exception  $exception
     * @return \Illuminate\Http\Response
     */
<<<<<<< HEAD
    public function render($request, Throwable $exception)
=======
    public function render($request, Exception $exception)
>>>>>>> 19f5e4d41d134205432345c7270c1d029cbe786e
    {
        return parent::render($request, $exception);
    }

    /**
     * Convert an authentication exception into an unauthenticated response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Auth\AuthenticationException  $exception
     * @return \Illuminate\Http\Response
     */
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($request->expectsJson()) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        return redirect()->guest(route('login'));
    }

    /**
     * Sends the exception email in demo server
     *
     * @param $exception
     */
<<<<<<< HEAD
    public function sendEmail(Throwable $exception)
    {
        try {
            $email = config('mail.username');

            if (!empty($email)) {
                $html = (string) $exception;
                Mail::to($email)->send(new ExceptionOccured($html));
            }
        } catch (Throwable $ex) {
            logger()->error($ex);
=======
    public function sendEmail(Exception $exception)
    {
        try {
            $e = FlattenException::create($exception);

            $handler = new SymfonyExceptionHandler();

            $html = $handler->getHtml($e);
            $email = config('mail.username');
            
            if (!empty($email)) {
                Mail::to($email)->send(new ExceptionOccured($html));
            }
        } catch (Exception $ex) {
            dd($ex);
>>>>>>> 19f5e4d41d134205432345c7270c1d029cbe786e
        }
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;
use Inertia\Inertia;
use App\Events\NewMessage;
use Auth;
use Gate;
use App\Models\Order;
use App\Models\Account;

class OrdersController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if(is_admin()) {
            $orders = Order::orderBy('created_at', 'DESC')->get();
        } else {
            $orders = Auth::user()->orders()->orderBy('created_at', 'DESC')->get();
        }
        
        return Inertia::render('Orders/Index', [
            'orders' => $orders
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $type = $request->product;
        $user = Auth::user();
        $order = $user->orders()->create([
            'uid' => Uuid::uuid4()->toString(),
            'status' => 'OPEN',
            'type' => $type
        ]);
        if($type === 'premium-account') {
            $stock = Account::whereNull('delivered_at')->whereNull('on_hold_until')->first();

            if(!$stock) {
                return redirect()->back();
            }

            $order->items()->create([
                'name' => 'Warzone Premium Account',
                'amount' => 1,
                'price' => 399
            ]);

            $stock->order_id = $order->id;
            $stock->on_hold_until = now()->addHours(1);
            $stock->save();

        } else if($type === 'wins') {
            $price = (int) str_replace('.', '', $request->price);
            if($price < 100) {
                $price = $price * 100;
            }
            $order->items()->create([
                'name' => $request->amount . 'x Warzone Wins',
                'amount' => 1,
                'price' => $price
            ]);
        
        } else if($type === 'lobby') {
            $price = (int) str_replace('.', '', $request->price);
            if($price < 100) {
                $price = $price * 100;
            }
            if($request->lobby === '2-hours-normal-speed') {
                $name = "Bot Lobby - 2 hours normal speed";
            } else if($request->lobby === '1-hour-25x-speed') {
                $name = "Bot Lobby - 1 hour 25x speed";
            } else if($request->lobby === 'max-level-max-guns') {
                $name = "Max Level and Max Guns";
            } else if($request->lobby === 'dark-aether-unlock-all-camos') {
                $name = "Dark Aether Unlock All Camos Instantly";
            }
            $order->items()->create([
                'name' => $name,
                'amount' => 1,
                'price' => $price
            ]);
        }
        $user->notify(new \App\Notifications\OrderCreated($order));
        return redirect()->route('orders.checkout', $order->uid);
    }

    public function pending($uid) {
        $order = Order::where('uid', $uid)->firstOrFail();
        $order->payment_gateway = 'PAYPAL';
        $order->save();
    }

    public function pay($uid) {
        $order = Order::where('uid', $uid)->firstOrFail();
        $order->payment_gateway = 'MANUAL';
        $order->status = 'PAID';
        $order->save();
        $order->deliver();
        return redirect()->route('orders.show', $uid);
    }

    public function cancel($uid) {
        $order = Order::where('uid', $uid)->firstOrFail();
        $order->status = 'CANCELED';
        $order->save();

        $message = $order->messages()->create([
            'body' => 'The order was marked as canceled.',
            'user_id' => 1
        ]);

        broadcast(new NewMessage($message));
    }

    public function deliver($uid) {
        $order = Order::where('uid', $uid)->firstOrFail();
        $order->status = 'DELIVERED';
        $order->save();

        $message = $order->messages()->create([
            'body' => 'The order was marked as delivered.',
            'user_id' => 1
        ]);

        broadcast(new NewMessage($message));
    }

    public function checkout($uid) {
        $user = Auth::user();
        $order = $user->orders()->where('uid', $uid)->firstOrFail();
        return Inertia::render('Orders/Checkout', [
            'order' => $order
        ]);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $user = Auth::user();
        $order = Order::where('uid', $id)->firstOrFail();
        // Gate::authorize('show-order', $order);
        return Inertia::render('Orders/Show', [
            'order' => $order
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }

    public function place($product_name) {
        if($product_name === 'premium-accounts') {
            $stock = Account::whereNull('delivered_at')->whereNull('on_hold_until')->count();
            return Inertia::render('Orders/Create/Accounts', [
                'stock' => $stock
            ]);
        } else if($product_name === 'wins') {
            return Inertia::render('Orders/Create/Wins');
        } else if($product_name === 'lobby') {
            return Inertia::render('Orders/Create/Lobby');
        } else {
            return redirect()->to('/');
        }
    }

    public function fail($uid) {
        $user = Auth::user();
        $order = $user->orders()->where('uid', $uid)->firstOrFail();
        return Inertia::render('Orders/Fail', [
            'order' => $order
        ]);
    }

    public function success($uid) {
        $user = Auth::user();
        $order = $user->orders()->where('uid', $uid)->firstOrFail();
        return redirect()->route('orders.show', $order->uid);
    }
}

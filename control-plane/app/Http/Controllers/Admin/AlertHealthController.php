<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Services\Alerts\ChannelSender;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
class AlertHealthController extends Controller {
    public function index(Request $request, ChannelSender $sender): View {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $cursor = DB::table('alert_processor_state')->where('id',1)->first();
        $maxId = (int) (DB::table('audit_events')->max('id') ?? 0);
        $pending = DB::table('alert_deliveries')->whereIn('status',['queued','retry','sending'])->count();
        $failed = DB::table('alert_deliveries')->where('status','failed')->count();
        $channels = collect(ChannelSender::CHANNELS)->map(fn($channel)=>[
            'name'=>$channel, 'configured'=>$sender->enabled($channel),
            'last'=>DB::table('alert_channel_tests')->where('channel',$channel)->orderByDesc('id')->first(),
        ]);
        return view('admin.alerts.health', [
            'lag'=>max(0,$maxId-(int)($cursor?->last_audit_event_id??0)),
            'pending'=>$pending,'failed'=>$failed,'channels'=>$channels,
            'lastRun'=>$cursor?->updated_at,
        ]);
    }
    public function test(Request $request, ChannelSender $sender): RedirectResponse {
        abort_unless($request->user()->isSuperAdmin(),403);
        $channel=$request->validate(['channel'=>['required',Rule::in(ChannelSender::CHANNELS)]])['channel'];
        $status='success'; $code='test_delivered';
        try {
            $sender->send($channel,['rule'=>'channel_test','severity'=>'info']);
        } catch (\Throwable) {
            $status='failed'; $code='test_delivery_failed';
        }
        DB::table('alert_channel_tests')->insert([
            'channel'=>$channel,'actor_user_id'=>$request->user()->id,
            'status'=>$status,'result_code'=>$code,'created_at'=>now(),
        ]);
        return back()->with($status==='success'?'success':'error',$code);
    }
}

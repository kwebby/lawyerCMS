<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Http\Controllers;

use App\Domain\Communications\NotificationDelivery;
use App\Support\Access;
use App\Support\Audit;
use Illuminate\Http\Request;

final class CommunicationSettingsController extends Controller
{
    public function preferences(Request $request, NotificationDelivery $delivery): mixed
    {
        $record = $request->isMethod('get') ? $delivery->preferences($request->user()->id) : $delivery->savePreferences($request->user()->id, $request->all());

        return response()->json(['data' => $record, 'categories' => NotificationDelivery::CATEGORIES]);
    }

    public function templates(Request $request, NotificationDelivery $delivery, Access $access, Audit $audit): mixed
    {
        $access->authorize($request->user(), 'settings.write');
        if ($request->isMethod('get')) {
            return response()->json(['data' => $delivery->templates()]);
        }
        $saved = $delivery->saveTemplate($request->all());
        $audit->log($request->user()->id, 'email_template.updated', 'email_templates', $saved['id']);

        return response()->json(['data' => $saved]);
    }
}

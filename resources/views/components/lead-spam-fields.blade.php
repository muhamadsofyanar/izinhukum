<div aria-hidden="true" style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden" tabindex="-1">
    <label for="website-{{ $id ?? 'lead' }}">Website</label>
    <input id="website-{{ $id ?? 'lead' }}" name="website" type="text" value="" autocomplete="off" tabindex="-1">
</div>
<input name="form_started_at" type="hidden" value="{{ now()->timestamp }}">

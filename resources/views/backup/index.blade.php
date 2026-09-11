@extends('layouts.app')
@section('title', __('lang_v1.backup'))

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1>@lang('lang_v1.backup')
    </h1>
</section>

<!-- Main content -->
<section class="content">
    
  @if (session('notification') || !empty($notification))
    <div class="row">
        <div class="col-sm-12">
            <div class="alert alert-danger alert-dismissible">
                <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                @if(!empty($notification['msg']))
                    {{$notification['msg']}}
                @elseif(session('notification.msg'))
                    {{ session('notification.msg') }}
                @endif
              </div>
          </div>  
      </div>     
  @endif

  <div class="row">
    <div class="col-sm-12">
      @component('components.widget', ['class' => 'box-primary'])
        @slot('tool')
          <div class="box-tools">
            <form method="POST" action="{{ url('backup/create') }}">
                @csrf
                <button type="submit" id="create-new-backup-button" class="btn btn-primary pull-right" style="margin-bottom:2em;"><i class="fa fa-plus"></i> @lang('lang_v1.create_new_backup')</button>
            </form>
          </div>
        @endslot
        @if (count($backups))
                <table class="table table-striped table-bordered">
                  <thead>
                  <tr>
                      <th>@lang('lang_v1.file')</th>
                      <th>@lang('lang_v1.size')</th>
                      <th>@lang('lang_v1.date')</th>
                      <th>@lang('lang_v1.age')</th>
                      <th>@lang('messages.actions')</th>
                  </tr>
                  </thead>
                    <tbody>
                    @foreach($backups as $backup)
                        <tr>
                            <td>{{ $backup['file_name'] }}</td>
                            <td>{{ humanFilesize($backup['file_size']) }}</td>
                            <td>
                                {{ Carbon::createFromTimestamp($backup['last_modified'])->toDateTimeString() }}
                            </td>
                            <td>
                                {{ Carbon::createFromTimestamp($backup['last_modified'])->diffForHumans(Carbon::now()) }}
                            </td>
                            <td>
                              <a class="btn btn-xs btn-success"
                                   href="{{action('App\Http\Controllers\BackUpController@download', [$backup['file_name']])}}"><i
                                        class="fa fa-cloud-download"></i> @lang('lang_v1.download')</a>
                                <form method="POST" action="{{action('App\Http\Controllers\BackUpController@delete', [$backup['file_name']])}}" class="backup-delete-form" style="display:inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-xs btn-danger"><i class="fa fa-trash-o"></i> @lang('messages.delete')</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
              </table>
            @else
                <div class="well">
                    <h4>There are no backups</h4>
                </div>
            @endif
            <br>
            <strong>@lang('lang_v1.auto_backup_instruction'):</strong><br>
            <code>{{$cron_job_command}}</code> <br>
            <strong>@lang('lang_v1.backup_clean_command_instruction'):</strong><br>
            <code>{{$backup_clean_cron_job_command}}</code>
      @endcomponent
    </div>
  </div>
</section>
@endsection

@section('javascript')
<script>
$(document).on('submit', '.backup-delete-form', function (event) {
    event.preventDefault();
    var form = this;
    swal({title: LANG.sure, icon: 'warning', buttons: true, dangerMode: true}).then(function (confirmed) {
        if (confirmed) { form.submit(); }
    });
});
</script>
@endsection

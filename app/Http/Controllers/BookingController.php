<?php

namespace App\Http\Controllers;

use App\Http\Requests\BookingStoreRequest;
use App\Jobs\SendEventBookingCreatedJob;
use App\Jobs\SendMassiveEventRequestJob;
use App\Jobs\SendPetitionRejectJob;
use App\Mail\MassiveEventRequestMail;
use App\Models\Assignment;
use App\Models\Booking;
use App\Models\Classroom;
use App\Models\Event;
use App\Models\Logbook;
use App\Models\Petition;
use App\Models\Schedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Http\Requests\BookingRequest;
use Hamcrest\Arrays\IsArray;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Spatie\Period\Boundaries;
use Spatie\Period\Period;
use Spatie\Period\PeriodCollection;
use Spatie\Period\Precision;
use SimpleSoftwareIO\QrCode\Facades\QrCode;


class BookingController extends Controller
{


    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        if (auth()->user()->hasAnyRole('admin', 'user', 'teacher')) {
            $bookings = DB::table('bookings')
                ->join('events', 'bookings.event_id', '=', 'events.id')
                ->join('classrooms', 'bookings.classroom_id', '=', 'classrooms.id')
                ->get(['bookings.id as booking_id', 'bookings.description as booking_description', 'bookings.booking_date as booking_date', 'bookings.start_time as start_time', 'bookings.finish_time as finish_time', 'bookings.status as status',
                    'events.event_name as event_name', 'classrooms.classroom_name as classroom_name']);

            $bookings_assignments = DB::table('bookings')
                ->join('assignments', 'bookings.assignment_id', '=', 'assignments.id')
                ->join('classrooms', 'bookings.classroom_id', '=', 'classrooms.id')
                ->get(['bookings.id as booking_id', 'bookings.description as booking_description', 'bookings.booking_date as booking_date', 'bookings.start_time as start_time', 'bookings.finish_time as finish_time', 'bookings.status as status',
                    'assignments.assignment_name as assignment_name', 'classrooms.classroom_name as classroom_name']);

            $classrooms = Classroom::all();

            return view('booking.index', compact('classrooms', 'bookings', 'bookings_assignments'));
        } else {
            abort(403);
        }
    }

    /**
     * Show the form for creating a new resource.
     *TODO: enviar solamente eventos creados por el usuario. Si es super usuario, enviar todo.
     */
    public function create()
    {
        return view('booking.create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * TODO:Validar fecha hasta dos semanas por StoreRequest
     */
    public function store(BookingStoreRequest $request)
    {
        try {
            if (auth()->user()->hasAnyRole('user', 'teacher')) {

                if ($this->checkOverlapping($request)->isEmpty()) {
                    $event = Event::create([
                        'event_name' => $request->event_name,
                        'user_id' => auth()->user()->id,
                        'participants' => $request->participants
                    ]);

                    $booking = Booking::create([
                        'user_id' => auth()->user()->id,
                        'classroom_id' => $request->classroom_id,
                        'event_id' => $event->id,
                        'description' => $request->description,
                        'status' => 'pending',
                        'week_day' => ucfirst(Carbon::parse($request->booking_date)->locale('es')->dayName),
                        'booking_date' => $request->booking_date,
                        'start_time' => $request->start_time,
                        'finish_time' => $request->finish_time,
                        'booking_uuid' => Uuid::uuid4()
                    ]);

                    Logbook::create([
                        'booking_id' => $booking->id,
                        'date' => $request->booking_date
                    ]);
                    $this->dispatch(new SendEventBookingCreatedJob($booking));
                    flash('Se ha registrado la reserva con exito')->success();
                    return redirect(route('bookings.mybookings'));
                } else {
                    return redirect(route('bookings.index'))->with('error', 'Ha ocurrido un error. Ya hay una reserva registrada');
                }
            } else if (auth()->user()->hasRole('admin')) {
                //Recibimos y manipulamos solo datos de materia
                if ($request->optionType == 'assignment') {
                    //Obtenemos user_id correspondiente a la materia para registrarlo
                    $assignment = Assignment::findOrFail($request->assignment_id);
                    $user_id = $assignment->users()->first()->id;
                    $detectDuplicates = collect(json_decode($request->arrayLocal))->duplicates();

                    if ($detectDuplicates->isEmpty()) {
                        $bookingData = json_decode($request->arrayLocal);
                        foreach ($bookingData as $booking) {
                            $assignmentDays = $this->getAllNameDays($request->start_date, $request->finish_date, $booking->day);
                            //Se obtienen las fechas entre el inicio y el fin de la materia que corresponden al dia indicado
                            foreach ($assignmentDays as $assignmentDay) {
                                $booking = Booking::create([
                                    'user_id' => $user_id,
                                    'classroom_id' => $booking->classroom_id,
                                    'assignment_id' => $request->assignment_id,
                                    'status' => 'pending',
                                    'description' => '',
                                    'week_day' => $booking->day,
                                    'booking_date' => $assignmentDay,
                                    'start_time' => Carbon::parse($booking->start_time)->format('H:i:s'),
                                    'finish_time' => Carbon::parse($booking->finish_time)->format('H:i:s'),
                                    'booking_uuid' => Uuid::uuid4()
                                ]);
                                Logbook::create([
                                    'booking_id' => $booking->id,
                                    'date' => $assignmentDay
                                ]);
                            }
                        }
                        //Se actualizan los valores de inicio y de fin para la materia indicada
                        $assignment->start_date = $request->start_date;
                        $assignment->finish_date = $request->finish_date;
                        if ($request->petition_id) {
                            $petition = Petition::findOrFail($request->petition_id);
                            $petition->status = 'solved';
                            $petition->save();
                        }

                        $assignment->save();

                        flash('Reservas de materia registradas con éxito')->success();
                        return redirect(route('bookings.index'));
                    } else {

                    }
                } else if ($request->optionType == 'massiveEvent') {
                    //Recibimos y manipulamos solo datos de evento
                    $detectDuplicates = collect(json_decode($request->arrayLocal))->duplicates();
                    if ($detectDuplicates->isEmpty()) {
                        $bookingData = json_decode($request->arrayLocal);

                        $event = Event::create([
                            'event_name' => $request->event_name,
                            'user_id' => $request->user_id, //se creará a nombre del admin
                            'participants' => ($request->participants_event * count($bookingData))
                        ]);

                        foreach ($bookingData as $booking) {
                            $booking = Booking::create([
                                'user_id' => $request->user_id,
                                'classroom_id' => $booking->classroom_id,
                                'event_id' => $event->id,
                                'description' => $request->description,
                                'status' => 'pending',
                                'week_day' => ucfirst(Carbon::parse($request->booking_date)->locale('es')->dayName),
                                'booking_date' => $request->booking_date,
                                'start_time' => Carbon::parse($booking->start_time)->format('H:i:s'),
                                'finish_time' => Carbon::parse($booking->finish_time)->format('H:i:s'),
                                'booking_uuid' => Uuid::uuid4()
                            ]);
                            Logbook::create([
                                'booking_id' => $booking->id,
                                'date' => $request->booking_date
                            ]);
                        }
                        flash('Reservas de evento registradas con éxito')->success();
                        return redirect(route('bookings.index'));
                    } else {
                        flash('Datos de reserva duplicados, imposible generar reserva')->error();
                        return back()->with('error', 'Datos de reserva duplicados, imposible generar reserva');
                    }
                } else {
                    flash('Ha ocurrido un error en la carga de reservas')->error();
                    return back()->with('error');
                }
            } else {
                return abort(403);
            }
        } catch (\Exception $e) {
            flash('Ha ocurrido un error al agregar una nueva reserva')->error();
            return back()->with('error catch' . $e);
        }
    }


    /**
     * Display the specified resource.
     *
     * @param int $id
     */
    public function show(Booking $booking)
    {
        return view('booking.show', compact('booking'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param int $id
     */
    public function edit(Booking $booking)
    {
        $assignments = Assignment::all();
        $events = Event::all();
        $classrooms = Classroom::all();
        return view('booking.edit', compact('booking', 'assignments', 'events', 'classrooms'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * TODO: no se edita
     */
    public function update(BookingRequest $request, $id)
    {

        try {
            $booking = Booking::findOrFail($id)->fill($request->all());

            $booking->save();

            flash('Reserva modificada con éxito')->success();
            return redirect(route('bookings.index'));
        } catch (\Exception $e) {
            flash('Ha ocurrido un error al actualizar la reserva')->error();
            return back();
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     */
    public function destroy($id)
    {

        try {
            $booking = Booking::findOrFail($id);
            $booking->delete();
            flash('destroyTrue')->success();
            return back();
        } catch (\Exception $e) {
            flash('destroyFalse');
            return back();
        }
    }

    /**
     * Función dedicada a comparar la disponibilidad de un aula y las reservas actuales sobre la misma.
     * Como resultado, retorna un conjunto de bloques de 30 minutos, distribuidos en subconjuntos de espacios de encontrarse
     * fragmentado múltiples veces.
     * Se hace uso de la libreria Period, para crear objetos Period y comparar los espacios disponibles.
     *
     * @param Request $request (los mismos conteniendo el id del aula y la fecha seleccionada)
     * @return array $gaps
     */
    public function getGaps(Request $request)
    {

        $totalTime = Schedule::where('classroom_id', $request->classroom_id)->where('day', Carbon::parse($request->date)->dayName)->get(['start_time', 'finish_time']);
        $availability = new PeriodCollection();
        $occupiedTimes = new PeriodCollection();

        //Se crean los Period de disponibilidad del aula
        foreach ($totalTime as $times) {
            $start_date = Carbon::today()->setTimeFromTimeString($times->start_time)->format('Y-m-d H:i:s');
            $finish_date = Carbon::today()->setTimeFromTimeString($times->finish_time)->format('Y-m-d H:i:s');

            $availability = $availability->add(Period::make($start_date, $finish_date, Precision::SECOND()));
        }
        //Se realizan queries para obtener los Period de los espacios ocupados por reservas de evento y materias
        $date = Carbon::parse($request->date);
        $reservedEvents = Booking::where('classroom_id', $request->classroom_id)->where('booking_date', $date->format('Y-m-d'))->get(['start_time', 'finish_time']);
        //$reservedAssignments = Booking::where('classroom_id', $request->classroom_id)->where('week_day', ucfirst($date->dayName))->get(['start_time', 'finish_time']);
        $occupiedTimesTemp = $reservedEvents;

        //Se crean los Period de los espacios ocupados
        foreach ($occupiedTimesTemp as $time) {
            $start_date = Carbon::today()->setTimeFromTimeString($time->start_time)->format('Y-m-d H:i:s');
            $finish_date = Carbon::today()->setTimeFromTimeString($time->finish_time)->format('Y-m-d H:i:s');

            $period = Period::make($start_date, $finish_date, Precision::SECOND());
            $occupiedTimes = $occupiedTimes->add($period);
        }

        //Se obtienen los espacios disponibles comparando la disponibilidad y las reservas sobre las mismas
        $totalGaps = $availability->subtract($occupiedTimes);

        //Se crean bloques de 30 minutos con los datos obtenidos anteriormente y se agrupan
        //según subconjunto de espacio disponible
        $gaps = [];
        $i = 0;
        foreach ($totalGaps as $elem) {

            $init = Carbon::today()->setTimeFromTimeString(($elem->start()->format('H:i:s')));
            $end = Carbon::today()->setTimeFromTimeString(($elem->end()->format('H:i:s')));
            $gaps[$i][] = $init->format('H:i');
            do {
                $gaps[$i][] = $init->addMinutes(30)->format('H:i');
            } while ($init->lessThan($end));
            $i++;
        }

        return $gaps;
    }

    /**
     * Función encargada de devolver solo las reservas del usuario.
     * La misma tiene un límite de hasta dos semanas y se ordena por fecha y hora de inicio
     */
    public function myBookings()
    {
        $assignmentsTeacher = Assignment::with('users')
            ->select(['id'])
            ->whereHas('users', function ($query) {
                $query->where('id', auth()->user()->id);
            })->get();
        $assignmentIds = [];

        foreach ($assignmentsTeacher as $assignment) {
            $assignmentIds[] = $assignment->id;
        }
        $bookings = Booking::where(function (Builder $query) use ($assignmentIds) {
            return $query->where('user_id', auth()->user()->id)->orWhereIn('assignment_id', $assignmentIds);
        })->whereBetween('booking_date', [Carbon::today()->format('Y-m-d'), Carbon::today()->addWeeks(2)->format('Y-m-d')])
            ->orderBy('booking_date', 'asc')
            ->orderBy('start_time', 'asc')
            ->get();
        return view('booking.mybookings', compact('bookings'));
    }

    /**
     * TODO: explicar
     */
    public function classroomBookings(Request $request)
    {
        $id = $request->classroom_id;
        $bookings = [];
        $bookings_assignments = [];

        $bookings = DB::table('bookings')
            ->join('events', 'bookings.event_id', '=', 'events.id')
            ->join('classrooms', 'bookings.classroom_id', '=', 'classrooms.id')
            ->where('classroom_id', $id)
            ->get(['bookings.id as booking_id', 'bookings.description as booking_description', 'bookings.booking_date as booking_date', 'bookings.start_time as start_time', 'bookings.finish_time as finish_time', 'bookings.status as status',
                'events.event_name as event_name', 'classrooms.classroom_name as classroom_name', 'bookings.classroom_id as classroom_id']);

        $bookings_assignments = DB::table('bookings')
            ->join('assignments', 'bookings.assignment_id', '=', 'assignments.id')
            ->join('classrooms', 'bookings.classroom_id', '=', 'classrooms.id')
            ->where('classroom_id', $id)
            ->get(['bookings.id as booking_id', 'bookings.booking_date as booking_date', 'bookings.description as booking_description', 'bookings.week_day as week_day', 'bookings.start_time as start_time', 'bookings.finish_time as finish_time', 'bookings.status as status',
                'assignments.assignment_name as assignment_name', 'classrooms.classroom_name as classroom_name', 'bookings.classroom_id as classroom_id']);

        $response = [$bookings, $bookings_assignments];

        return $response;
    }

    /**
     * Funcion para redireccionar a CreateAdmin desde Petition
     */
    public function createFromPetition(Request $request)
    {
        $petition = Petition::findOrFail($request->id);
        $assignments = Assignment::all();
        return view('booking.adminCreate', compact('petition', 'assignments'));
    }

    /**
     * Funcion para redireccionar a CreateAdmin desde Calendar
     */
    public function createAdmin(Request $request)
    {
        $assignments = Assignment::has('users')->get();
        $users = User::all();
        return view('booking.adminCreate', compact('assignments', 'users'));
    }

    /**
     * Función que se encarga de obtener las aulas según
     * $request que contiene día de la semana y cantidad de participantes
     */

    public function getClassroomsByQuery(Request $request)
    {
        if ($request->booking_date) {
            $request->day = Carbon::parse($request->booking_date)->locale('es')->dayName;
        }
        $filterClassroomsByDay = Schedule::where('day', $request->day)->get('classroom_id'); //Obtiene aulas que tienen horarios ese dia

        if ($request->classroom_type) {
            $filterClassrooms = DB::table('classrooms')->whereIn('id', $filterClassroomsByDay) //Busca las aulas
            ->where('capacity', '>=', $request->participants)->where('type', $request->classroom_type)->orderBy('capacity', 'asc')->get(); //filtra por cantidad de participantes
        } else {
            $filterClassrooms = DB::table('classrooms')->whereIn('id', $filterClassroomsByDay) //Busca las aulas
            ->where('capacity', '>=', $request->participants)->orderBy('capacity', 'asc')->get(); //filtra por cantidad de participantes
        }

        return $filterClassrooms;
    }


    /**
     * Función encargada de obtener los horarios disponibles para un salón, comparando con posibles eventos que posean reservas en el
     * dia de la materia y otras materias
     * $request contiene classroom_id, start_date, finish_date y day
     */
    public function getClassroomsGaps(Request $request)
    {
        // Para aulas con horarios disponibles se debe chequear que no choquen con reservas en fechas especificas para ese dia de la semana
        // classroom_id + start_date / finish_date + day (chequear formato del day)


        $totalTime = Schedule::where('classroom_id', $request->classroom_id)->where('day', $request->day)->get(['start_time', 'finish_time']);
        $availability = new PeriodCollection();
        $occupiedTimes = new PeriodCollection();
        //Se crean los Period de disponibilidad del aula
        foreach ($totalTime as $times) {
            $start_date = Carbon::today()->setTimeFromTimeString($times->start_time)->format('Y-m-d H:i:s');
            $finish_date = Carbon::today()->setTimeFromTimeString($times->finish_time)->format('Y-m-d H:i:s');

            $availability = $availability->add(Period::make($start_date, $finish_date, Precision::SECOND()));
        }

        //Se realizan queries para obtener los Period de los espacios ocupados por reservas de evento y materias
        //Helpers para obtener dia de la semana localizado al inglés desde el español

        //Obtiene todas las fechas que corresponden al dia de la semana indicado en un determinado rango de fechas
        $dayNameInRange = $this->getAllNameDays($request->start_date, $request->finish_date, $request->day);

        $reservedEvents = Booking::where('classroom_id', $request->classroom_id)->whereIn('booking_date', $dayNameInRange)->get(['start_time', 'finish_time']);
        //$reservedAssignments = Booking::where('classroom_id', $request->classroom_id)->where('week_day', $request->day)->get(['start_time', 'finish_time']);
        $occupiedTimesTemp = $reservedEvents;

        //Se crean los Period de los espacios ocupados
        foreach ($occupiedTimesTemp as $time) {
            $start_date = Carbon::today()->setTimeFromTimeString($time->start_time)->format('Y-m-d H:i:s');
            $finish_date = Carbon::today()->setTimeFromTimeString($time->finish_time)->format('Y-m-d H:i:s');

            $period = Period::make($start_date, $finish_date, Precision::SECOND());
            $occupiedTimes = $occupiedTimes->add($period);
        }

        //Se obtienen los espacios disponibles comparando la disponibilidad y las reservas sobre las mismas
        $totalGaps = $availability->subtract($occupiedTimes);

        //Se crean bloques de 30 minutos con los datos obtenidos anteriormente y se agrupan
        //según subconjunto de espacio disponible
        $gaps = [];
        $i = 0;
        foreach ($totalGaps as $elem) {

            $init = Carbon::today()->setTimeFromTimeString(($elem->start()->format('H:i:s')));
            $end = Carbon::today()->setTimeFromTimeString(($elem->end()->format('H:i:s')));
            $gaps[$i][] = $init->format('H:i');
            do {
                $gaps[$i][] = $init->addMinutes(30)->format('H:i');
            } while ($init->lessThan($end));
            $i++;
        }

        return $gaps;
    }

    /**
     * Función que se encarga de generar todas las fechas que sean dia-nombre dentro de un rango de fechas.
     * @param string $fromDate representa el inicio del rango. Formato 'Y-m-d'
     * @param string $toDate representa el fin del rango. Formato 'Y-m-d'
     * @param string $day representa el dia nombre a seleccionar. Debe estar en inglés
     * @return array
     */
    public
    function getAllNameDays($fromDate, $toDate, $day)
    {
        $daysESP = [
            'domingo' => 0,
            'lunes' => 1,
            'martes' => 2,
            'miércoles' => 3,
            'jueves' => 4,
            'viernes' => 5,
            'sábado' => 6,
        ];
        $dayFormatted = Carbon::getDays()[$daysESP[strtolower($day)]];
        $days = [];
        $startDate = Carbon::parse($fromDate)->modify('this ' . $dayFormatted);
        $endDate = Carbon::parse($toDate);

        for ($date = $startDate; $date->lte($endDate); $date->addWeek()) {
            $days[] = $date->format('Y-m-d');
        }

        return $days;
    }

//obtenemos las reservas de aulas de informatica para el dia actual,seran mostradas en el diagrama de Gantt
    public function getClassroom(Request $request)
    {
        if (!$request->booking_date) {
            $today = Carbon::today()->format('Y-m-d');
        } else {
            $today = Carbon::parse($request->booking_date)->format('Y-m-d');
        }

        $classrooms = Classroom::where('building', 'Informática')->get();
        $response = [];

        $collection = collect(); //
        foreach ($classrooms as $classroom) {
            $arregloJS = [];
            // $collection->push($classroom); //
            $classroom_bookings = $classroom->bookings->where('booking_date', $today)->where('status', '!==', 'cancelled');


            foreach ($classroom_bookings as $obj) {

                $objAssignment = $obj->assignment;
                if ($objAssignment != null) {
                    $name = $objAssignment->assignment_name;
                    $tipo = "materia";
                } else {
                    $objEvent = $obj->event;
                    $name = $objEvent->event_name;
                    $tipo = "evento";
                }
                $arregloJS[] = ['name' => $name, 'start_time' => $obj->start_time, 'finish_time' => $obj->finish_time, 'tipo' => $tipo];
            };

            $response[] = ['classroom_name' => $classroom->classroom_name, 'bookings' => $arregloJS];
        }
        $collection->push($response);
        return json_encode($collection);
    }

    /**
     * Función dedicada a validar que no exista superposición en la data entrante
     */
    public function checkOverlapping($data)
    {
        //Tenemos booking_date, classroom_id, start_time y finish_time
        //Hay que obtener las posibles interferencias para el mismo booking date y classroom
        $posibleOverlap = Booking::where('booking_date', $data->booking_date)
            ->where('classroom_id', $data->classroom_id)->get(['booking_date', 'start_time', 'finish_time']);

        $periodOverlap = new PeriodCollection();

        foreach ($posibleOverlap as $booking) {
            $start_time = Carbon::createFromTimeString($booking->start_time)->format('Y-m-d H:i:s');
            $finish_time = Carbon::createFromTimeString($booking->finish_time)->format('Y-m-d H:i:s');

            $periodOverlap = $periodOverlap->add(Period::make($start_time, $finish_time, PRECISION::SECOND(), boundaries: Boundaries::EXCLUDE_END()));
        }

        $start_time = Carbon::createFromTimeString($data->start_time)->format('Y-m-d H:i:s');
        $finish_time = Carbon::createFromTimeString($data->finish_time)->format('Y-m-d H:i:s');
        $toCheckPeriod = new PeriodCollection(Period::make($start_time, $finish_time, PRECISION::SECOND(), boundaries: Boundaries::EXCLUDE_END()));

        $response = $toCheckPeriod->overlapAll($periodOverlap);

        return $response;

    }

    /**
     * Función que se encarga de tomar la información a enviar en un mail al administrador
     */
    public function sendPetitionFromMessage(Request $request)
    {
        try {
            $user = User::where('email', $request->user_mail)->first();
            $response = collect(['user' => $user, 'mail' => $request->user_mail, 'subject' => $request->subject, 'description' => $request->description]);
            $this->dispatch(new SendMassiveEventRequestJob($response));
            return back()->with('success', 'Petición enviada exitosamente. El administrador le responderá a la brevedad al mail registrado en su cuenta');
        } catch (\Exception $e) {
            return back()->with('error', 'Ha ocurrido un error al procesar la petición');
        }
    }

}


// mensajes de error con sweetAlert
$(document).ready(function () {
    var $errorMessage = $('#errorsMsj').text().trim();
    if ($('#errorsMsj').length > 0) {
        var timerInterval
        Swal.fire({
            toast: true,
            position: 'bottom-end',
            background: '#f27474',
            color: '#000',
            showConfirmButton: false,
            html: $errorMessage,
            timer: 7000,
            timerProgressBar: true,
            willClose: () => {
                clearInterval(timerInterval)
            }
        })
    }


    // mensajes de flash() con sweetAlert
    var flash = $('#flashMessage');
    if (flash.find('.alert.alert-success').length > 0) {
        var contentFlash = $("#flashMessage:first").text().trim();
        switch (contentFlash) {
            // CREACION DE RESERVA
            case 'Se ha registrado la reserva con exito':
                var timerInterval
                Swal.fire({
                    toast: true,
                    position: 'bottom-end',
                    background: '#a5dc86',
                    color: '#000',
                    showConfirmButton: false,
                    html: 'Reserva creada con éxito.',
                    timer: 2000,
                    timerProgressBar: true,
                    willClose: () => {
                        clearInterval(timerInterval)
                    }
                })
                break;

            // ERROR CREACION DE RESERVA
            case 'Ha ocurrido un error al agregar una nueva reserva':
                var timerInterval
                Swal.fire({
                    toast: true,
                    position: 'bottom-end',
                    background: '#f27474',
                    color: '#000',
                    showConfirmButton: false,
                    html: 'Error al crear reserva.',
                    timer: 2000,
                    timerProgressBar: true,
                    willClose: () => {
                        clearInterval(timerInterval)
                    }
                })
                break;

            // MODIFICACION DE RESERVA
            case 'Reserva modificada con éxito':
                var timerInterval
                Swal.fire({
                    toast: true,
                    position: 'bottom-end',
                    background: '#a5dc86',
                    color: '#000',
                    showConfirmButton: false,
                    html: 'Reserva modificada con éxito.',
                    timer: 2000,
                    timerProgressBar: true,
                    willClose: () => {
                        clearInterval(timerInterval)
                    }
                })
                break;

            // ERROR MODIFICACION DE MATERIA
            case 'Ha ocurrido un error al actualizar la reserva':
                var timerInterval
                Swal.fire({
                    toast: true,
                    position: 'bottom-end',
                    background: '#f27474',
                    color: '#000',
                    showConfirmButton: false,
                    html: 'Error al modificar reserva.',
                    timer: 2000,
                    timerProgressBar: true,
                    willClose: () => {
                        clearInterval(timerInterval)
                    }
                })
                break;
        }
    }
});
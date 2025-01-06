<?php
session_start();
if (empty($_SESSION['active'])) {
    header('Location: ../');
    exit;
}

include('../conexion.php');

function convertirNumeroEnLetras($numero) {
    $f = new NumberFormatter("es", NumberFormatter::SPELLOUT);
    $parteEntera = floor($numero);
    $parteDecimal = round(($numero - $parteEntera) * 100);
    
    $letras = strtoupper($f->format($parteEntera));
    $decimal = str_pad($parteDecimal, 2, '0', STR_PAD_LEFT);
    
    return "SON $letras CON $decimal/100 SOLES";
}

if (isset($_GET['id_pedido'])) {
    $id_pedido = intval($_GET['id_pedido']);

    // Consulta para obtener los datos del pedido y del cliente
    $query = "SELECT pl.nombre AS producto, dp.precio, dp.cantidad, CONCAT(c.nombres, ' ', c.apellidos) AS cliente,c.documento, 
              c.direccion, p.id AS id_pedido, p.total
              FROM pedidos p
              INNER JOIN detalle_pedidos dp ON p.id = dp.id_pedido
              INNER JOIN cliente c ON c.id = p.id_cliente
              INNER JOIN productos pl ON pl.id = dp.id_producto
              WHERE p.id = $id_pedido";

    $result = mysqli_query($conexion, $query);

    if ($result && mysqli_num_rows($result) > 0) {
        $pedido_data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $pedido_data[] = $row;
        }

        $leyenda = convertirNumeroEnLetras($pedido_data[0]['total']);

        // Llamada a la API para generar el PDF con los datos de la boleta
        $api_url = "https://facturacion.apisperu.com/api/v1/invoice/pdf";
        $token = "Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJ1c2VybmFtZSI6IkFuZHJ5IiwiY29tcGFueSI6IjIxMTExMTExMTE4IiwiaWF0IjoxNzMxNDE5MjQxLCJleHAiOjgwMzg2MTkyNDF9.tATCiTFBQ3fwT70Mzt1PlQPssIrqeWeSM7Hw3X6ARV7vNZcdskNNrDPZkJzUUe0pSGiDwbCPbcTXoLNvZyn1u3ggqRhov1ALqpBY59Kto3nI3jv74vXK4W3F9ZNohINws-4gCOP-W2oBK_rCtyuseR7fuYoJyd_7MF4ouwi-LDzh8kyVqs2nny94GxLTcr4fl5sU2BokqUsd-bJPrT9E7pcoyok2YEVSbNAzeyfwnLWANLy5_shpLgSx6FQWoftuJgzfO-RpILK6RAqTIAu3431rhlDSWJjBJBvXtj3lbPpycUfRmBvYvKrfuI2oU0eBKgUw9hBvCWgm_A4ZPveq2LbWZV0RIrtM3B2CC0bR0pR7CYYZMrlEIdQuBzkWOaMMC88I3n8JS9wCE3vYhDCnujvtmCITx8OnGyN24qbCAI6iS0oR7O1t6pLghSIGWpvSNQsTAH5OknBoWgycOWxBfDK1rVIZ72g-F3f-RyAqqNmBMT63e0vEfMxyckedAodfMtoYusYBTWn1gfz7UQiw8d3P6_DlZBaTivbjWWQFOWvr4vzUmpHKoh5kTTj-X4YvJSay8dXOnqnWh7xcozlUnhKrY5LEqNsIQ2nys7ub__j6TsSHYKjiH0MCx76WrHzK2m9Mr3dqUObM0ieFExOxYi5Qz2JQy_c6GrZ9VaybSBU"; // Tu token aquí

        $body = [
            "ublVersion" => "2.1",
            "tipoOperacion" => "0101",
            "tipoDoc" => "03",
            "serie" => "B001",
            "correlativo" => strval($id_pedido),
            "fechaEmision" => date("Y-m-d\TH:i:sP"),
            "formaPago" => ["moneda" => "PEN", "tipo" => "Contado"],
            "tipoMoneda" => "PEN",
            "client" => [
                "tipoDoc" => "1",
                "numDoc" => $pedido_data[0]['documento'],
                "rznSocial" => $pedido_data[0]['cliente'],
                "address" => [
                    "direccion" => $pedido_data[0]['direccion'],
                    "provincia" => "LORETO",
                    "departamento" => "MAYNAS",
                    "distrito" => "IQUITOS",
                    "ubigueo" => "150101"
                ]
            ],
            "company" => [
                "ruc" => "21111111118",
                "razonSocial" => "RESTAURANT-CEVICHERIA \"EL CANGREJO PICHANGUERO\"",
                "nombreComercial" => "Mi empresa",
                "address" => [
                    "direccion" => "Morona 1150",
                    "provincia" => "LORETO",
                    "departamento" => "MAYNAS",
                    "distrito" => "IQUITOS",
                    "ubigueo" => "150101"
                ]
            ],
            "mtoOperGravadas" => $pedido_data[0]['total'],
            "mtoIGV" => 0,
            "valorVenta" => $pedido_data[0]['total'],
            "totalImpuestos" => 0,
            "subTotal" => $pedido_data[0]['total'],
            "mtoImpVenta" => $pedido_data[0]['total'],
            "details" => array_map(function($item) {
                return [
                    "codProducto" => "P001",
                    "unidad" => "NIU",
                    "descripcion" => $item['producto'],
                    "cantidad" => $item['cantidad'],
                    "mtoValorUnitario" => $item['precio'],
                    "mtoValorVenta" => $item['cantidad'] * $item['precio'],
                    "mtoBaseIgv" => $item['cantidad'] * $item['precio'],
                    "porcentajeIgv" => 0,
                    "igv" => 0,
                    "tipAfeIgv" => 10,
                    "totalImpuestos" => 0,
                    "mtoPrecioUnitario" => $item['precio']
                ];
            }, $pedido_data),
            "legends" => [
                ["code" => "1000", "value" => $leyenda]
            ]
        ];

        // Configurar la solicitud con cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: $token"
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));

        $response = curl_exec($ch);
        curl_close($ch);

        // Mostrar el PDF
        if ($response) {
            header("Content-Type: application/pdf");
            echo $response;
        } else {
            echo "Error al generar el PDF.";
        }
    } else {
        echo "No se encontraron datos para el pedido.";
    }
} else {
    echo "ID de pedido no especificado.";
}
?>
use std::io::{BufRead, BufReader, Write};
use std::net::TcpListener;

fn main() {
    let address = "0.0.0.0:8080";
    let listener = TcpListener::bind(address).expect("failed to bind");
    println!("Listening on {address}");

    for stream in listener.incoming() {
        let Ok(mut stream) = stream else {
            continue;
        };

        let mut request_line = String::new();
        let _ = BufReader::new(&stream).read_line(&mut request_line);

        let body = "Hello World";
        let response = format!(
            "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{}",
            body.len(),
            body
        );
        let _ = stream.write_all(response.as_bytes());
    }
}

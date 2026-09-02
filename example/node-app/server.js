import { createServer } from 'node:http'

const port = Number(process.env.PORT ?? 3000)

const server = createServer((request, response) => {
    response.writeHead(200, { 'content-type': 'text/plain; charset=utf-8' })
    response.end('Hello from Node!\n')
})

// 0.0.0.0 and not localhost: the router reaches this container over the Docker
// network, and a server bound to the loopback answers only inside the container
// it runs in.
server.listen(port, '0.0.0.0', () => {
    console.log(`Listening on http://0.0.0.0:${port}`)
})

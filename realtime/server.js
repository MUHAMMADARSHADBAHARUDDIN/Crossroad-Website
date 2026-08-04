const http = require("http");
const fs = require("fs");
const path = require("path");
const { WebSocketServer, WebSocket } = require("ws");

const envPath = path.join(__dirname, "..", ".env");
if(fs.existsSync(envPath)){
    for(const rawLine of fs.readFileSync(envPath, "utf8").split(/\r?\n/)){
        const line = rawLine.trim();
        if(!line || line.startsWith("#") || !line.includes("=")){ continue; }
        const separator = line.indexOf("=");
        const key = line.slice(0, separator).trim();
        const value = line.slice(separator + 1).trim().replace(/^["']|["']$/g, "");
        if(key && process.env[key] === undefined){ process.env[key] = value; }
    }
}

const port = Number(process.env.CROSSROAD_REALTIME_PORT || process.env.PORT || 8081);
const publishSecret = String(process.env.CROSSROAD_REALTIME_SECRET || "");
const clients = new Set();

const server = http.createServer((request, response) => {
    if(request.method === "GET" && request.url === "/health"){
        response.writeHead(200, { "Content-Type": "application/json" });
        response.end(JSON.stringify({ ok: true, clients: clients.size }));
        return;
    }

    if(request.method !== "POST" || request.url !== "/publish"){
        response.writeHead(404);
        response.end();
        return;
    }

    const suppliedSecret = String(request.headers["x-realtime-secret"] || "");
    if(!publishSecret || suppliedSecret !== publishSecret){
        response.writeHead(403, { "Content-Type": "application/json" });
        response.end(JSON.stringify({ ok: false }));
        return;
    }

    let body = "";
    request.on("data", chunk => {
        body += chunk;
        if(body.length > 16384){ request.destroy(); }
    });
    request.on("end", () => {
        let event;
        try { event = JSON.parse(body); } catch { event = null; }
        if(!event || typeof event.channel !== "string"){
            response.writeHead(400, { "Content-Type": "application/json" });
            response.end(JSON.stringify({ ok: false }));
            return;
        }

        const message = JSON.stringify({
            type: "data_changed",
            channel: event.channel.slice(0, 60),
            action: String(event.action || "updated").slice(0, 120),
            changed_at: new Date().toISOString()
        });
        for(const client of clients){
            if(client.readyState === WebSocket.OPEN){ client.send(message); }
        }
        response.writeHead(200, { "Content-Type": "application/json" });
        response.end(JSON.stringify({ ok: true, delivered: clients.size }));
    });
});

const websocketServer = new WebSocketServer({ server, path: "/ws", maxPayload: 1024 });
websocketServer.on("connection", socket => {
    socket.isAlive = true;
    clients.add(socket);
    socket.on("pong", () => { socket.isAlive = true; });
    socket.on("close", () => clients.delete(socket));
    socket.on("error", () => clients.delete(socket));
});

const heartbeat = setInterval(() => {
    for(const socket of clients){
        if(!socket.isAlive){ socket.terminate(); clients.delete(socket); continue; }
        socket.isAlive = false;
        socket.ping();
    }
}, 30000);

server.on("close", () => clearInterval(heartbeat));
server.listen(port, "127.0.0.1", () => {
    console.log(`Crossroad realtime service listening on 127.0.0.1:${port}`);
});

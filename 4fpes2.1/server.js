const express = require("express");
const fs = require("fs");
const cors = require("cors");
const app = express();

app.use(cors());
app.use(express.json());

const evalFile = "./evaluations.json";
const userFile = "./users.json";

// --- Evaluations ---
app.get("/evaluations", (req, res) => {
  fs.readFile(evalFile, "utf8", (err, data) => {
    if (err) return res.status(500).json({ error: "Failed to read data" });
    res.json(JSON.parse(data || "[]"));
  });
});

app.post("/evaluations", (req, res) => {
  const newEval = req.body;
  fs.readFile(evalFile, "utf8", (err, data) => {
    if (err) return res.status(500).json({ error: "Failed to read file" });

    let evaluations = JSON.parse(data || "[]");
    evaluations.push(newEval);

    fs.writeFile(evalFile, JSON.stringify(evaluations, null, 2), (err) => {
      if (err) return res.status(500).json({ error: "Failed to save data" });
      res.json({ success: true, message: "Evaluation saved" });
    });
  });
});

// --- Users ---
app.get("/users", (req, res) => {
  fs.readFile(userFile, "utf8", (err, data) => {
    if (err) return res.status(500).json({ error: "Failed to read users" });
    res.json(JSON.parse(data || "[]"));
  });
});

app.post("/users", (req, res) => {
  const newUser = req.body;
  fs.readFile(userFile, "utf8", (err, data) => {
    if (err) return res.status(500).json({ error: "Failed to read file" });

    let users = JSON.parse(data || "[]");
    users.push(newUser);

    fs.writeFile(userFile, JSON.stringify(users, null, 2), (err) => {
      if (err) return res.status(500).json({ error: "Failed to save user" });
      res.json({ success: true, message: "User added" });
    });
  });
});

app.delete("/users/:username", (req, res) => {
  const username = req.params.username;
  fs.readFile(userFile, "utf8", (err, data) => {
    if (err) return res.status(500).json({ error: "Failed to read file" });

    let users = JSON.parse(data || "[]");
    users = users.filter(u => u.username !== username);

    fs.writeFile(userFile, JSON.stringify(users, null, 2), (err) => {
      if (err) return res.status(500).json({ error: "Failed to save file" });
      res.json({ success: true, message: "User deleted" });
    });
  });
});

app.listen(3000, () => {
  console.log("Server running on http://localhost:3000");
});

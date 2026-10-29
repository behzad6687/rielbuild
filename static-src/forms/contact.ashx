<%@ WebHandler Language="C#" Class="ContactHandler" %>
// The estimate form on every page posts here. It checks the fields, stops obvious spam,
// emails the request (settings in web.config > appSettings) and keeps a copy in
// App_Data\estimate-requests.log. Fetch requests get JSON, plain posts go back to the page.

using System;
using System.Collections.Generic;
using System.Configuration;
using System.IO;
using System.Net;
using System.Net.Mail;
using System.Text;
using System.Text.RegularExpressions;
using System.Web;
using System.Web.Caching;

public class ContactHandler : IHttpHandler
{
    const string Phone = "647-895-4555";

    static readonly Dictionary<string, string> Services = new Dictionary<string, string>
    {
        { "home-renovation", "Full Home Renovation" },
        { "kitchen-renovation", "Kitchen Renovation" },
        { "bathroom-renovation", "Bathroom Renovation" },
        { "basement-finishing", "Basement Finishing" },
        { "other", "Something else" }
    };

    static readonly object LogLock = new object();

    public bool IsReusable { get { return true; } }

    public void ProcessRequest(HttpContext ctx)
    {
        HttpRequest req = ctx.Request;
        HttpResponse res = ctx.Response;
        res.TrySkipIisCustomErrors = true;
        res.Cache.SetCacheability(HttpCacheability.NoCache);
        bool ajax = (req.Headers["X-Requested-With"] ?? "") == "fetch";

        if (req.HttpMethod != "POST")
        {
            res.StatusCode = 405;
            res.AppendHeader("Allow", "POST");
            return;
        }

        string src = Clean(req.Form["_src"], 200);
        if (!src.StartsWith("/") || src.StartsWith("//")) src = "/contact/";

        // Bots fill the hidden field or post within two seconds of the page loading (_ts holds the
        // milliseconds the visitor spent on the page): thank them and drop it.
        long elapsed;
        bool tooFast = long.TryParse(req.Form["_ts"], out elapsed) && elapsed < 2000;
        if (!string.IsNullOrEmpty(req.Form["website"]) || tooFast)
        {
            Finish(ctx, ajax, src, true, null, null);
            return;
        }

        if (RateLimited(req.UserHostAddress ?? "unknown"))
        {
            Finish(ctx, ajax, src, false, null, "Too many requests from here. Please call us at " + Phone + ".");
            return;
        }

        string name = Clean(req.Form["name"], 80);
        string phone = Clean(req.Form["phone"], 40);
        string email = Clean(req.Form["email"], 120);
        string service = Clean(req.Form["service"], 60);
        string message = (req.Form["message"] ?? "").Trim();
        if (message.Length > 2000) message = message.Substring(0, 2000);

        var errors = new Dictionary<string, string>();
        if (name.Length == 0) errors["name"] = "Please fill this in.";
        if (!Regex.IsMatch(phone, @"^[0-9+()\-\s.]{10,20}$")) errors["phone"] = "Please enter a phone number we can call.";
        if (!Regex.IsMatch(email, @"^[^@\s]+@[^@\s]+\.[^@\s]{2,}$")) errors["email"] = "Please enter a valid email.";
        if (!Services.ContainsKey(service)) errors["service"] = "Please fill this in.";
        if (errors.Count > 0)
        {
            Finish(ctx, ajax, src, false, errors, "Please check the highlighted fields.");
            return;
        }

        string project = Services[service];
        string subject = "Estimate: " + project + " (" + name + ")";
        var body = new StringBuilder();
        body.AppendLine("New estimate request from rielbuild.ca");
        body.AppendLine();
        body.AppendLine("Name:    " + name);
        body.AppendLine("Phone:   " + phone);
        body.AppendLine("Email:   " + email);
        body.AppendLine("Project: " + project);
        body.AppendLine();
        body.AppendLine("About the project:");
        body.AppendLine(message.Length > 0 ? message : "(nothing added)");
        body.AppendLine();
        body.AppendLine("Sent from: https://rielbuild.ca" + src);
        body.AppendLine("Time:      " + DateTime.Now.ToString("yyyy-MM-dd HH:mm"));
        body.AppendLine("IP:        " + req.UserHostAddress);

        bool logged = Log(ctx, subject, body.ToString());
        bool sent = Send(subject, body.ToString(), email, name);
        if (!sent && !logged)
        {
            Finish(ctx, ajax, src, false, null, "We could not send this right now. Please call us at " + Phone + " or email contact@rielbuild.ca.");
            return;
        }
        Finish(ctx, ajax, src, true, null, null);
    }

    static bool Send(string subject, string body, string replyTo, string replyName)
    {
        try
        {
            string to = Setting("Rb.MailTo", "contact@rielbuild.ca");
            string from = Setting("Rb.MailFrom", "contact@rielbuild.ca");
            using (var msg = new MailMessage())
            {
                msg.From = new MailAddress(from, "RIELBUILD website");
                foreach (string addr in to.Split(new[] { ',', ';' }, StringSplitOptions.RemoveEmptyEntries))
                    msg.To.Add(addr.Trim());
                msg.ReplyToList.Add(new MailAddress(replyTo, replyName));
                msg.Subject = subject.Replace("\r", " ").Replace("\n", " ");
                msg.Body = body;
                msg.BodyEncoding = Encoding.UTF8;
                msg.SubjectEncoding = Encoding.UTF8;

                string host = Setting("Rb.SmtpHost", "");
                using (var smtp = host.Length > 0 ? new SmtpClient(host) : new SmtpClient())
                {
                    if (host.Length > 0)
                    {
                        int port;
                        if (int.TryParse(Setting("Rb.SmtpPort", "587"), out port)) smtp.Port = port;
                        smtp.EnableSsl = Setting("Rb.SmtpSsl", "true").ToLowerInvariant() == "true";
                        if (smtp.EnableSsl) ServicePointManager.SecurityProtocol |= (SecurityProtocolType)3072; // TLS 1.2
                        string user = Setting("Rb.SmtpUser", "");
                        if (user.Length > 0) smtp.Credentials = new NetworkCredential(user, Setting("Rb.SmtpPass", ""));
                    }
                    smtp.Timeout = 20000;
                    smtp.Send(msg);
                }
            }
            return true;
        }
        catch (Exception ex)
        {
            LogError(ex);
            return false;
        }
    }

    static bool Log(HttpContext ctx, string subject, string body)
    {
        try
        {
            string dir = ctx.Server.MapPath("~/App_Data");
            Directory.CreateDirectory(dir);
            lock (LogLock)
            {
                File.AppendAllText(Path.Combine(dir, "estimate-requests.log"),
                    "==== " + subject + Environment.NewLine + body + Environment.NewLine, Encoding.UTF8);
            }
            return true;
        }
        catch
        {
            return false;
        }
    }

    static void LogError(Exception ex)
    {
        try
        {
            string dir = HttpContext.Current.Server.MapPath("~/App_Data");
            Directory.CreateDirectory(dir);
            lock (LogLock)
            {
                File.AppendAllText(Path.Combine(dir, "mail-errors.log"),
                    DateTime.Now.ToString("yyyy-MM-dd HH:mm") + " " + ex.GetType().Name + ": " + ex.Message + Environment.NewLine, Encoding.UTF8);
            }
        }
        catch { }
    }

    // At most 6 requests per address in 10 minutes.
    static bool RateLimited(string ip)
    {
        string key = "rb-form:" + ip;
        Cache cache = HttpRuntime.Cache;
        lock (LogLock)
        {
            object hits = cache[key];
            int n = hits == null ? 0 : (int)hits;
            if (n >= 6) return true;
            if (hits == null)
                cache.Insert(key, 1, null, DateTime.UtcNow.AddMinutes(10), Cache.NoSlidingExpiration);
            else
                cache[key] = n + 1;
        }
        return false;
    }

    static void Finish(HttpContext ctx, bool ajax, string src, bool ok, Dictionary<string, string> errors, string message)
    {
        HttpResponse res = ctx.Response;
        if (!ajax)
        {
            if (ok)
            {
                string path = src.Split('?', '#')[0];
                res.Redirect(path + "?sent=contact", false);
            }
            else
            {
                res.ContentType = "text/plain; charset=utf-8";
                res.Write((message ?? "Please check the form.") + " Go back and try again.");
            }
            ctx.ApplicationInstance.CompleteRequest();
            return;
        }

        var json = new StringBuilder("{\"ok\":" + (ok ? "true" : "false"));
        if (message != null) json.Append(",\"message\":" + Json(message));
        if (errors != null && errors.Count > 0)
        {
            json.Append(",\"errors\":{");
            bool first = true;
            foreach (var kv in errors)
            {
                if (!first) json.Append(",");
                json.Append(Json(kv.Key) + ":" + Json(kv.Value));
                first = false;
            }
            json.Append("}");
        }
        json.Append("}");
        res.ContentType = "application/json; charset=utf-8";
        res.Write(json.ToString());
    }

    static string Json(string s)
    {
        var sb = new StringBuilder("\"");
        foreach (char c in s)
        {
            if (c == '"' || c == '\\') sb.Append('\\').Append(c);
            else if (c < 0x20 || c == '<' || c == '>') sb.Append("\\u").Append(((int)c).ToString("x4"));
            else sb.Append(c);
        }
        return sb.Append('"').ToString();
    }

    static string Clean(string s, int max)
    {
        s = Regex.Replace((s ?? "").Trim(), @"[\r\n\t]+", " ");
        return s.Length > max ? s.Substring(0, max) : s;
    }

    static string Setting(string key, string fallback)
    {
        string v = ConfigurationManager.AppSettings[key];
        return string.IsNullOrEmpty(v) ? fallback : v.Trim();
    }
}
